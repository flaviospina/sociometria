<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ClassGroup;
use App\Models\Tenant\Enrollment;
use App\Models\Tenant\Student;
use App\Services\AuditLogger;
use App\Services\QuotaService;
use App\Services\SchoolAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function __construct(
        private readonly SchoolAccess $access,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, string $class): JsonResponse
    {
        $class = $this->findAccessible($class);
        $students = $class->students()->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // Acesso a dados identificáveis de estudantes é sempre auditado (sem nomes no log).
        $this->audit->record('students.listed', 'success', $request->user(), $this->context->institution(), 'class_group', $class->public_id, [
            'count' => $students->count(),
        ]);

        return response()->json(['data' => $students]);
    }

    public function store(Request $request, string $class, QuotaService $quota): JsonResponse
    {
        $class = $this->findAccessible($class);
        abort_unless($this->access->canManageStructure(), 403);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'birth_date' => 'nullable|date_format:Y-m-d|before:today',
            'registration_code' => 'nullable|string|max:40',
        ]);
        $quota->assertCanCreateStudent();

        $student = DB::connection('tenant')->transaction(function () use ($data, $class) {
            $student = new Student($data);
            $student->school_id = $class->school_id;
            $student->save();
            Enrollment::query()->create(['student_id' => $student->id, 'class_group_id' => $class->id]);

            return $student;
        });

        $this->audit->record('student.created', 'success', $request->user(), $this->context->institution(), 'student', $student->public_id);

        return response()->json(['data' => $student], 201);
    }

    private function findAccessible(string $publicId): ClassGroup
    {
        $class = ClassGroup::query()->where('public_id', $publicId)->first();
        abort_if($class === null || ! $this->access->canAccessClass($class), 404);

        return $class;
    }
}
