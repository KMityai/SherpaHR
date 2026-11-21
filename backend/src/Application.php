<?php
declare(strict_types=1);

final class ApiError extends RuntimeException {}

final class Application
{
    private int $userId = 1;
    /** Selected demo persona, loaded once per request. */
    private array $user = [];

    public function __construct(private PDO $db) {}

    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($method === 'GET' && $path === '/api/health') {
                $this->respond(['status' => 'ok']);
                return;
            }
            // Local demonstration only: this is a persona selector, not authentication.
            $header = $_SERVER['HTTP_X_DEMO_USER'] ?? '1';
            if (!ctype_digit($header) || !$this->one('SELECT id FROM users WHERE id = ?', [(int) $header])) {
                throw new ApiError('Неизвестный демо-пользователь', 401);
            }
            $this->userId = (int) $header;
            $this->user = $this->one('SELECT * FROM users WHERE id = ?', [$this->userId]);
            $this->dispatch($method, $path);
        } catch (ApiError $error) {
            http_response_code($error->getCode());
            echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            error_log($error->__toString());
            http_response_code(500);
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
        }
    }

    private function dispatch(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/api/context') {
            $this->respond([
                'user' => $this->one('SELECT * FROM users WHERE id = ?', [$this->userId]),
                'users' => $this->all('SELECT * FROM users ORDER BY id'),
                'server_now' => gmdate('Y-m-d\TH:i:s\Z'),
                'seeded_at' => $this->db->query("SELECT value FROM metadata WHERE key = 'seeded_at'")->fetchColumn(),
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/api/projects') {
            $projects = $this->all('SELECT p.*, (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id) AS task_count FROM projects p JOIN project_members m ON m.project_id=p.id WHERE m.user_id=? ORDER BY p.archived,p.id', [$this->userId]);
            foreach ($projects as &$project) {
                $project['archived'] = (bool) $project['archived'];
                $project['members'] = $this->all('SELECT u.* FROM users u JOIN project_members m ON m.user_id=u.id WHERE m.project_id=? ORDER BY u.id', [$project['id']]);
                // Escalations are the manager's view only; archived projects are out of the process.
                $project['escalation_count'] = $this->isManager($project) ? (int) $this->db->query(
                    'SELECT COUNT(*) FROM tasks WHERE project_id=' . (int) $project['id'] . ' AND escalated=1 AND status<>\'done\''
                )->fetchColumn() : 0;
            }
            unset($project);
            $this->respond($projects);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/tasks$#', $path, $match)) {
            $project = $this->project((int) $match[1]);
            if ($method === 'GET') {
                // Escalated tasks go first, the rest keeps the previous order.
                $this->respond($this->all('SELECT t.* FROM tasks t JOIN users u ON u.id=t.assignee_id WHERE t.project_id=? ORDER BY t.escalated DESC, t.id', [$project['id']]));
                return;
            }
            if ($method === 'POST') {
                $this->writable($project);
                $data = $this->input();
                $fields = $this->validateTask($data, $project, false);
                $fields = $this->row($fields);
                $stmt = $this->db->prepare('INSERT INTO tasks (project_id,title,description,status,assignee_id,due_at,escalated,updated_at) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$project['id'], $fields['title'], $fields['description'], $fields['status'], $fields['assignee_id'], $fields['due_at'], $fields['escalated'], gmdate('Y-m-d\TH:i:s\Z')]);
                $this->respond($this->task((int) $this->db->lastInsertId()), 201);
                return;
            }
        }
        if (preg_match('#^/api/tasks/(\d+)$#', $path, $match)) {
            $task = $this->task((int) $match[1]);
            if ($method === 'GET') {
                $task['comments'] = $this->all('SELECT c.*, u.name AS author FROM comments c JOIN users u ON u.id=c.user_id WHERE task_id=? ORDER BY c.id', [$task['id']]);
                $this->respond($task);
                return;
            }
            if ($method === 'PATCH') {
                $project = $this->project($task['project_id']);
                $this->writable($project);
                $data = $this->input();
                $fields = $this->validateTask($data, $project, true);
                if (!isset($data['version']) || !is_int($data['version'])) {
                    throw new ApiError('Нужна целочисленная версия задачи', 422);
                }
                $updated = array_merge($task, $fields);
                $updated['escalated'] = (int) ($fields['escalated'] ?? $task['escalated']);
                $stmt = $this->db->prepare('UPDATE tasks SET title=?,description=?,status=?,assignee_id=?,due_at=?,escalated=?,updated_at=?,version=version+1 WHERE id=? AND version=?');
                $stmt->execute([$updated['title'], $updated['description'], $updated['status'], $updated['assignee_id'], $updated['due_at'], $updated['escalated'], gmdate('Y-m-d\TH:i:s\Z'), $task['id'], $data['version']]);
                if ($stmt->rowCount() !== 1) {
                    throw new ApiError('Задача изменилась. Откройте её заново перед сохранением.', 409);
                }
                $this->respond($this->task($task['id']));
                return;
            }
        }
        if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/comments$#', $path, $match)) {
            $task = $this->task((int) $match[1]);
            $this->writable($this->project($task['project_id']));
            $data = $this->input();
            $body = $this->text($data['body'] ?? null, 'Комментарий', 5000, true);
            $createdAt = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare('INSERT INTO comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$task['id'], $this->userId, $body, $createdAt]);
            $this->respond([
                'id' => (int) $this->db->lastInsertId(),
                'task_id' => (int) $task['id'],
                'user_id' => $this->userId,
                'body' => $body,
                'created_at' => $createdAt,
                'author' => $this->user['name'],
            ], 201);
            return;
        }
        throw new ApiError('Маршрут не найден', 404);
    }

    private function validateTask(array $data, array $project, bool $partial): array
    {
        $unknown = array_diff(array_keys($data), ['title', 'description', 'status', 'assignee_id', 'due_at', 'escalated', 'version']);
        if ($unknown) {
            throw new ApiError('Неизвестные поля задачи', 422);
        }
        $out = [];
        if (!$partial || array_key_exists('title', $data)) {
            $out['title'] = $this->text($data['title'] ?? null, 'Название', 200, true);
        }
        if (!$partial || array_key_exists('description', $data)) {
            $out['description'] = $this->text($data['description'] ?? '', 'Описание', 10000, false);
        }
        if (!$partial || array_key_exists('status', $data)) {
            $status = $data['status'] ?? 'open';
            if (!in_array($status, ['open', 'in_progress', 'blocked', 'done'], true)) {
                throw new ApiError('Недопустимый статус', 422);
            }
            $out['status'] = $status;
        }
        if (!$partial || array_key_exists('assignee_id', $data)) {
            $id = $data['assignee_id'] ?? null;
            if ($id !== null && (!is_int($id) || !$this->one('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?', [$project['id'], $id]))) {
                throw new ApiError('Исполнитель должен быть участником проекта', 422);
            }
            $out['assignee_id'] = $id;
        }
        if (!$partial || array_key_exists('due_at', $data)) {
            $due = $data['due_at'] ?? null;
            if ($due !== null) {
                if (!is_string($due)) {
                    throw new ApiError('Срок должен быть ISO 8601 в UTC или null', 422);
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $due, new DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $due) {
                    throw new ApiError('Срок должен быть ISO 8601 в UTC или null', 422);
                }
            }
            $out['due_at'] = $due;
        }
        if (!$partial || array_key_exists('escalated', $data)) {
            $escalated = $data['escalated'] ?? false;
            if (!is_bool($escalated)) {
                throw new ApiError('Признак «Нужен руководитель» должен быть true или false', 422);
            }
            $out['escalated'] = $escalated;
        }
        $status = $out['status'] ?? null;
        $escalated = $out['escalated'] ?? null;
        // A full update always carries a status; a partial one only when it is sent.
        if ($status === 'done' || ($status === null && $escalated === true)) {
            // A finished task never requires the manager, so the pair cannot stay inconsistent.
            if ($escalated === true) {
                throw new ApiError('Завершённая задача не может требовать руководителя', 422);
            }
            $out['escalated'] = false;
        }
        if (!$project['archived']) {
            return $out;
        }
        // Archival is read-only for task fields; a flag that is already set cannot be kept either.
        $unchanged = array_filter($out, fn($value, $key) => (bool) ($data[$key] ?? false) === (bool) $value, ARRAY_FILTER_USE_BOTH);
        $out = array_intersect_key($out, $unchanged);
        if (($out['escalated'] ?? false) !== false) {
            throw new ApiError('Архивный проект не участвует в эскалации', 422);
        }
        return $out;
    }

    /**
     * Validated field values are typed for JSON; the database stores a flag as 0/1.
     */
    private function row(array $fields): array
    {
        return isset($fields['escalated']) ? array_merge($fields, ['escalated' => (int) $fields['escalated']]) : $fields;
    }

    private function text(mixed $value, string $name, int $max, bool $required): string
    {
        if (!is_string($value) || ($required && trim($value) === '') || strlen($value) > $max) {
            throw new ApiError($name . ': некорректная строка или превышен лимит байтов ' . $max, 422);
        }
        return trim($value);
    }

    private function input(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
            throw new ApiError('Слишком большой запрос', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, 32769);
        if (strlen($raw) > 32768) {
            throw new ApiError('Слишком большой запрос', 413);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError('Некорректный JSON', 400);
        }
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw new ApiError('Ожидается JSON-объект', 400);
        }
        return $data;
    }

    private function project(int $id): array
    {
        $project = $this->one('SELECT p.* FROM projects p JOIN project_members m ON m.project_id=p.id WHERE p.id=? AND m.user_id=?', [$id, $this->userId]);
        if (!$project) {
            throw new ApiError('Проект не найден', 404);
        }
        return $project;
    }

    /**
     * Task escalations are shown to the project manager only: the owner of an
     * active project who holds the manager role (the demo owner is «руководитель»).
     * Archived projects never take part in escalation.
     */
    private function isManager(array $project): bool
    {
        return !$project['archived']
            && (int) $project['owner_id'] === $this->userId
            && str_contains($this->user['name'] ?? '', 'руководитель');
    }

    private function task(int $id): array
    {
        $task = $this->one('SELECT t.* FROM tasks t JOIN project_members m ON m.project_id=t.project_id WHERE t.id=? AND m.user_id=?', [$id, $this->userId]);
        if (!$task) {
            throw new ApiError('Задача не найдена', 404);
        }
        return $task;
    }

    private function writable(array $project): void
    {
        if ($project['archived']) {
            throw new ApiError('Архивный проект доступен только для чтения', 409);
        }
    }

    private function one(string $sql, array $params = []): array|false
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function respond(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
