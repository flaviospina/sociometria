<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\School;
use App\Services\AuditLogger;
use App\Services\QuotaService;
use App\Services\SchoolAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    public function __construct(private readonly SchoolAccess $access) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->access->visibleSchools()->orderBy('name')->get()]);
    }

    public function show(string $school): JsonResponse
    {
        return response()->json(['data' => $this->findVisible($school)]);
    }

    public function store(Request $request, QuotaService $quota, AuditLogger $audit, TenantContext $context): JsonResponse
    {
        // Criar escola é ato de administração institucional.
        abort_unless($this->access->institutionWide(), 403);
        $data = $request->validate(['name' => 'required|string|max:160']);
        $quota->assertCanCreateSchool();

        $school = School::query()->create($data);
        $audit->record('school.created', 'success', $request->user(), $context->institution(), 'school', $school->public_id);

        return response()->json(['data' => $school], 201);
    }

    public function classes(string $school): JsonResponse
    {
        $school = $this->findVisible($school);

        return response()->json(['data' => $this->access->visibleClasses($school)->orderBy('name')->get()]);
    }

    /** 404 tanto para "não existe" quanto para "fora do seu escopo" (não revela existência). */
    private function findVisible(string $publicId): School
    {
        $school = School::query()->where('public_id', $publicId)->first();
        abort_if($school === null || ! $this->access->canViewSchool($school), 404);

        return $school;
    }
}
