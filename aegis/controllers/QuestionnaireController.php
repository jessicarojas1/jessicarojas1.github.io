<?php
class QuestionnaireController {

    public function index(): void {
        Auth::requireAuth();

        $questionnaires = Database::fetchAll(
            "SELECT q.*,
                    u.name AS creator_name,
                    COUNT(DISTINCT qq.id) AS question_count,
                    COUNT(DISTINCT qa.id) AS assignment_count
             FROM questionnaires q
             LEFT JOIN users u ON q.created_by = u.id
             LEFT JOIN questionnaire_questions qq ON qq.questionnaire_id = q.id
             LEFT JOIN questionnaire_assignments qa ON qa.questionnaire_id = q.id
             GROUP BY q.id, u.name
             ORDER BY q.created_at DESC"
        );

        $myAssignments = Database::fetchAll(
            "SELECT qa.*,
                    q.title AS questionnaire_title,
                    q.entity_type
             FROM questionnaire_assignments qa
             JOIN questionnaires q ON q.id = qa.questionnaire_id
             WHERE qa.assigned_to = ?
               AND qa.status IN ('pending','in_progress')
             ORDER BY qa.due_date ASC NULLS LAST",
            [Auth::id()]
        );

        $activeModule = 'questionnaire';
        require AEGIS_ROOT . '/views/questionnaire/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('policy.write');

        $users = Database::fetchAll(
            "SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name"
        );

        $activeModule = 'questionnaire';
        require AEGIS_ROOT . '/views/questionnaire/create.php';
    }

    public function create(): void {
        Auth::requirePermission('policy.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title      = Security::sanitizeInput($_POST['title'] ?? '');
        $desc       = Security::sanitizeInput($_POST['description'] ?? '');
        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? 'general');

        $allowedEntityTypes = ['general', 'vendor', 'audit'];
        if (!in_array($entityType, $allowedEntityTypes, true)) {
            $entityType = 'general';
        }

        if (!$title) {
            $_SESSION['q_error'] = 'Questionnaire title is required.';
            header('Location: /questionnaire/create');
            return;
        }

        $qId = Database::insert('questionnaires', [
            'title'       => $title,
            'description' => $desc,
            'entity_type' => $entityType,
            'created_by'  => Auth::id(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $questions = $_POST['questions'] ?? [];
        $sortOrder = 0;
        foreach ($questions as $q) {
            $section      = Security::sanitizeInput($q['section'] ?? 'General');
            $questionText = Security::sanitizeInput($q['question_text'] ?? '');
            $questionType = Security::sanitizeInput($q['question_type'] ?? 'text');
            $optionsRaw   = Security::sanitizeInput($q['options_raw'] ?? '');
            $weight       = max(1, min(5, (int)($q['weight'] ?? 1)));
            $isRequired   = !empty($q['is_required']) ? 1 : 0;

            if (!$questionText) {
                continue;
            }

            $allowedTypes = ['text', 'scale', 'boolean', 'choice'];
            if (!in_array($questionType, $allowedTypes, true)) {
                $questionType = 'text';
            }

            $options = null;
            if ($questionType === 'choice' && $optionsRaw !== '') {
                $opts = array_values(array_filter(
                    array_map('trim', explode(',', $optionsRaw))
                ));
                if ($opts) {
                    $options = json_encode($opts);
                }
            }

            Database::insert('questionnaire_questions', [
                'questionnaire_id' => $qId,
                'section'          => $section,
                'question_text'    => $questionText,
                'question_type'    => $questionType,
                'options'          => $options,
                'weight'           => $weight,
                'is_required'      => $isRequired,
                'sort_order'       => ++$sortOrder,
            ]);
        }

        Auth::log('create', 'questionnaires', $qId, ['title' => $title]);

        header('Location: /questionnaire');
    }

    public function view(string $id): void {
        Auth::requireAuth();

        $id = (int)$id;

        $questionnaire = Database::fetchOne(
            "SELECT q.*, u.name AS creator_name
             FROM questionnaires q
             LEFT JOIN users u ON q.created_by = u.id
             WHERE q.id = ?",
            [$id]
        );

        if (!$questionnaire) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $allQuestions = Database::fetchAll(
            "SELECT * FROM questionnaire_questions
             WHERE questionnaire_id = ?
             ORDER BY sort_order ASC",
            [$id]
        );

        // Group questions by section
        $sections = [];
        foreach ($allQuestions as $q) {
            $sections[$q['section']][] = $q;
        }

        $assignments = Database::fetchAll(
            "SELECT qa.*, u.name AS assignee_name
             FROM questionnaire_assignments qa
             LEFT JOIN users u ON qa.assigned_to = u.id
             WHERE qa.questionnaire_id = ?
             ORDER BY qa.created_at DESC",
            [$id]
        );

        $users = Database::fetchAll(
            "SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name"
        );

        $activeModule = 'questionnaire';
        require AEGIS_ROOT . '/views/questionnaire/view.php';
    }

    public function assign(string $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /questionnaire/' . (int)$id);
            return;
        }

        Auth::requirePermission('policy.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $questionnaireId = (int)$id;
        $entityType      = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $entityId        = !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null;
        $assignedTo      = (int)($_POST['assigned_to'] ?? 0);
        $dueDate         = Security::sanitizeInput($_POST['due_date'] ?? '');

        if (!$assignedTo) {
            header('Location: /questionnaire/' . $questionnaireId);
            return;
        }

        $assignmentId = Database::insert('questionnaire_assignments', [
            'questionnaire_id' => $questionnaireId,
            'entity_type'      => $entityType ?: null,
            'entity_id'        => $entityId,
            'assigned_to'      => $assignedTo,
            'due_date'         => $dueDate ?: null,
            'status'           => 'pending',
            'created_by'       => Auth::id(),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        Auth::log('assign', 'questionnaire_assignments', $assignmentId, [
            'questionnaire_id' => $questionnaireId,
            'assigned_to'      => $assignedTo,
        ]);

        header('Location: /questionnaire/' . $questionnaireId);
    }

    public function respond(string $assignmentId): void {
        Auth::requireAuth();

        $assignmentId = (int)$assignmentId;
        $currentUser  = Auth::user();

        $assignment = Database::fetchOne(
            "SELECT qa.*, q.title AS questionnaire_title, q.id AS questionnaire_id
             FROM questionnaire_assignments qa
             JOIN questionnaires q ON q.id = qa.questionnaire_id
             WHERE qa.id = ?",
            [$assignmentId]
        );

        if (!$assignment) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        // Only the assigned user or admin may respond
        if ((int)$assignment['assigned_to'] !== Auth::id() && $currentUser['role'] !== 'admin') {
            http_response_code(403);
            require AEGIS_ROOT . '/views/errors/403.php';
            return;
        }

        $questions = Database::fetchAll(
            "SELECT * FROM questionnaire_questions
             WHERE questionnaire_id = ?
             ORDER BY sort_order ASC",
            [$assignment['questionnaire_id']]
        );

        // Group by section
        $sections = [];
        foreach ($questions as $q) {
            $sections[$q['section']][] = $q;
        }

        // Load existing answers if in_progress
        $existingAnswers = [];
        if ($assignment['status'] === 'in_progress' && !empty($assignment['response_id'])) {
            $rows = Database::fetchAll(
                "SELECT * FROM questionnaire_answers WHERE response_id = ?",
                [$assignment['response_id']]
            );
            foreach ($rows as $row) {
                $existingAnswers[$row['question_id']] = $row['answer_value'];
            }
        }

        $activeModule = 'questionnaire';
        require AEGIS_ROOT . '/views/questionnaire/respond.php';
    }

    public function submitResponse(string $assignmentId): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /questionnaire');
            return;
        }

        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $assignmentId = (int)$assignmentId;
        $currentUser  = Auth::user();

        $assignment = Database::fetchOne(
            "SELECT * FROM questionnaire_assignments WHERE id = ?",
            [$assignmentId]
        );

        if (!$assignment) {
            http_response_code(404);
            return;
        }

        if ((int)$assignment['assigned_to'] !== Auth::id() && $currentUser['role'] !== 'admin') {
            http_response_code(403);
            return;
        }

        // Create or reuse the response record
        $responseId = Database::insert('questionnaire_responses', [
            'assignment_id'   => $assignmentId,
            'questionnaire_id'=> $assignment['questionnaire_id'],
            'respondent_id'   => Auth::id(),
            'submitted_at'    => date('Y-m-d H:i:s'),
            'total_score'     => 0,
            'max_score'       => 0,
        ]);

        $questions = Database::fetchAll(
            "SELECT * FROM questionnaire_questions
             WHERE questionnaire_id = ?",
            [$assignment['questionnaire_id']]
        );

        $totalScore = 0;
        $maxScore   = 0;
        $answers    = $_POST['answers'] ?? [];

        foreach ($questions as $question) {
            $qId     = (int)$question['id'];
            $type    = $question['question_type'];
            $weight  = (int)$question['weight'];
            $rawVal  = $answers[$qId] ?? null;
            $answerValue = ($rawVal !== null) ? Security::sanitizeInput((string)$rawVal) : '';

            $score = 0.0;

            switch ($type) {
                case 'scale':
                    $numericVal = max(1, min(5, (int)$answerValue));
                    $answerValue = (string)$numericVal;
                    $score = ($numericVal / 5) * $weight;
                    $maxScore += $weight;
                    break;

                case 'boolean':
                    $score = (strtolower($answerValue) === 'yes') ? (float)$weight : 0.0;
                    $maxScore += $weight;
                    break;

                case 'choice':
                    $score = ($answerValue !== '') ? 1.0 : 0.0;
                    $maxScore += 1;
                    break;

                case 'text':
                default:
                    // Text answers are not scored
                    $score = 0.0;
                    break;
            }

            $totalScore += $score;

            Database::insert('questionnaire_answers', [
                'response_id'  => $responseId,
                'question_id'  => $qId,
                'answer_value' => $answerValue,
                'score'        => round($score, 4),
            ]);
        }

        // Update the response totals
        Database::query(
            "UPDATE questionnaire_responses
             SET total_score = ?, max_score = ?
             WHERE id = ?",
            [round($totalScore, 4), round($maxScore, 4), $responseId]
        );

        // Mark assignment submitted
        Database::query(
            "UPDATE questionnaire_assignments
             SET status = 'submitted', response_id = ?, submitted_at = ?
             WHERE id = ?",
            [$responseId, date('Y-m-d H:i:s'), $assignmentId]
        );

        Auth::log('submit', 'questionnaire_responses', $responseId, [
            'assignment_id' => $assignmentId,
            'total_score'   => round($totalScore, 4),
            'max_score'     => round($maxScore, 4),
        ]);

        header('Location: /questionnaire');
    }
}
