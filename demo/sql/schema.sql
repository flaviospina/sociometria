-- demo/sql/schema.sql
-- Sociometria Escolar — MODELO DE DEMONSTRAÇÃO (somente dados fictícios)
-- Compatível com MySQL 5.7+ e MariaDB 10.3+. Idempotente: pode ser importado mais de uma vez.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'teacher'   -- admin | teacher
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  city VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  grade VARCHAR(40) NOT NULL,
  school_year SMALLINT UNSIGNED NOT NULL,
  teacher_name VARCHAR(120) NOT NULL,
  KEY idx_classes_school (school_id),
  CONSTRAINT fk_classes_school FOREIGN KEY (school_id) REFERENCES schools (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Turmas que cada professor pode acessar (admin acessa todas).
CREATE TABLE IF NOT EXISTS user_classes (
  user_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, class_id),
  CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_uc_class FOREIGN KEY (class_id) REFERENCES classes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  call_number SMALLINT UNSIGNED NOT NULL,       -- número de chamada
  KEY idx_students_class (class_id),
  CONSTRAINT fk_students_class FOREIGN KEY (class_id) REFERENCES classes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perguntas (critérios) do questionário sociométrico.
CREATE TABLE IF NOT EXISTS criteria (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  label VARCHAR(60) NOT NULL,
  question VARCHAR(255) NOT NULL,
  polarity TINYINT NOT NULL DEFAULT 1,          -- 1 = aproximação, -1 = dificuldade
  max_choices TINYINT UNSIGNED NOT NULL DEFAULT 3,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  applied_on DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'closed',  -- open | closed
  KEY idx_app_class (class_id),
  CONSTRAINT fk_app_class FOREIGN KEY (class_id) REFERENCES classes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um registro por aluno elegível em cada aplicação; answered_at NULL = não participou.
CREATE TABLE IF NOT EXISTS participations (
  application_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  access_code CHAR(6) NOT NULL,
  answered_at DATETIME NULL,
  PRIMARY KEY (application_id, student_id),
  UNIQUE KEY uq_access_code (access_code),
  CONSTRAINT fk_part_app FOREIGN KEY (application_id) REFERENCES applications (id),
  CONSTRAINT fk_part_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  criterion_id INT UNSIGNED NOT NULL,
  chooser_id INT UNSIGNED NOT NULL,
  chosen_id INT UNSIGNED NOT NULL,
  choice_rank TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_choice (application_id, criterion_id, chooser_id, chosen_id),
  KEY idx_choice_app_crit (application_id, criterion_id),
  CONSTRAINT fk_choice_app FOREIGN KEY (application_id) REFERENCES applications (id),
  CONSTRAINT fk_choice_crit FOREIGN KEY (criterion_id) REFERENCES criteria (id),
  CONSTRAINT fk_choice_chooser FOREIGN KEY (chooser_id) REFERENCES students (id),
  CONSTRAINT fk_choice_chosen FOREIGN KEY (chosen_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interventions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NULL,                  -- NULL = ação para a turma toda
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  category VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'planejada', -- planejada | em_andamento | concluida
  owner_name VARCHAR(120) NOT NULL,
  due_on DATE NULL,
  created_at DATETIME NOT NULL,
  KEY idx_int_class (class_id),
  CONSTRAINT fk_int_class FOREIGN KEY (class_id) REFERENCES classes (id),
  CONSTRAINT fk_int_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem fez o quê e quando (sem dados de respostas).
CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
