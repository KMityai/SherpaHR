<?php
declare(strict_types=1);

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    // Every call opens its own socket: the built-in server closes it after the
    // response, so a request can never be served from a previous connection.
    $connection = curl_init($base . $path);
    $headers = ['Content-Type: application/json', 'X-Demo-User: ' . $user, 'Expect:', 'Connection: close'];
    curl_setopt_array($connection, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($connection, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($connection);
    if ($raw === false) {
        throw new RuntimeException(curl_error($connection));
    }
    $status = curl_getinfo($connection, CURLINFO_RESPONSE_CODE);
    curl_close($connection);
    return [$status, json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>', 'status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1]);
    check($status === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/tasks/12');
    $version = $response['data']['version'];
    check($status === 200 && $response['data']['escalated'] === 0, 'escalation flag is 0/1');
    check(request('PATCH', '/api/tasks/12', ['escalated' => 'yes', 'version' => 1])[0] === 422, 'flag must be boolean');
    // A full update carries the whole task: task 12 is the seeded open one of this project.
    [$status, $response] = request('PATCH', '/api/tasks/12', [
        'title' => $response['data']['title'], 'description' => $response['data']['description'],
        'status' => 'open', 'assignee_id' => 2, 'due_at' => null, 'escalated' => true, 'version' => $version,
    ]);
    check($status === 200 && $response['data']['escalated'] === 1, 'escalation starts on save');
    // A task created with the flag is escalated from the first request.
    [$status, $response] = request('POST', '/api/projects/1/tasks', ['title' => 'Нужен руководитель', 'assignee_id' => 2, 'escalated' => true]);
    $escalated = $response['data'];
    check($status === 201 && $escalated['escalated'] === 1, 'task starts escalated');
    [$status, $response] = request('GET', '/api/projects');
    check(array_column($response['data'], 'escalation_count', 'id') === [1 => 3, 2 => 1, 3 => 0, 4 => 0], 'manager sees the open escalations');
    check(array_column(request('GET', '/api/projects', null, 2)[1]['data'], 'escalation_count', 'id') === [1 => 0, 2 => 0, 4 => 0], 'escalations stay with the manager');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    // Task 12 is escalated by this run, 1 comes from the seed, the created task closes the tail.
    $ids = array_map('intval', array_column($response['data'], 'id'));
    check(array_slice($ids, 0, 3) === [1, 12, $escalated['id']], 'escalated tasks are listed first');
    check(array_slice($ids, 3) === [2, 3, 4], 'rest keeps task order');
    check(array_slice(array_column($response['data'], 'escalated'), 3) === array_fill(0, count($response['data']) - 3, 0), 'tail carries no flag');
    // A task can be closed without clearing the checkbox: the flag is dropped, not refused.
    [$status, $response] = request('PATCH', '/api/tasks/' . $escalated['id'], [
        'title' => 'Нужен руководитель', 'description' => '', 'status' => 'done',
        'assignee_id' => null, 'due_at' => null, 'version' => 1,
    ]);
    check($status === 200 && $response['data']['status'] === 'done' && $response['data']['escalated'] === 0, 'closing drops the flag');
    [$status, $response] = request('PATCH', '/api/tasks/' . $escalated['id'], [
        'title' => 'Нужен руководитель', 'description' => '', 'status' => 'open',
        'assignee_id' => 2, 'due_at' => null, 'version' => 2,
    ]);
    check($status === 200 && $response['data']['status'] === 'open' && $response['data']['escalated'] === 0, 'done task can be reopened');
    [$status, $response] = request('PATCH', '/api/tasks/' . $escalated['id'], [
        'title' => 'Нужен руководитель', 'description' => '', 'status' => 'open',
        'assignee_id' => 2, 'due_at' => null, 'escalated' => true, 'version' => 3,
    ]);
    check($status === 200 && $response['data']['escalated'] === 1 && $response['data']['status'] === 'open', 'escalation returns on save');
    [$status, $response] = request('GET', '/api/projects');
    check(array_column($response['data'], 'escalation_count', 'id') === [1 => 3, 2 => 1, 3 => 0, 4 => 0], 'counter counts open escalations only');
    [$status, $response] = request('PATCH', '/api/tasks/' . $escalated['id'], ['title' => 'Нужен руководитель', 'description' => '', 'status' => 'blocked', 'assignee_id' => 2, 'due_at' => null, 'escalated' => true, 'version' => 4]);
    check($status === 200 && $response['data']['escalated'] === 1 && $response['data']['status'] === 'blocked', 'escalation survives status change');
    [$status, $response] = request('PATCH', '/api/tasks/' . $escalated['id'], ['title' => 'Нужен руководитель', 'description' => '', 'status' => 'done', 'assignee_id' => null, 'due_at' => null, 'escalated' => false, 'version' => 5]);
    check($status === 200 && $response['data']['escalated'] === 0 && $response['data']['status'] === 'done', 'finishing clears the flag');
    [$status, $response] = request('GET', '/api/projects');
    check(array_column($response['data'], 'escalation_count', 'id') === [1 => 2, 2 => 1, 3 => 0, 4 => 0], 'finished task leaves the counter');
    [$status, $response] = request('PATCH', '/api/tasks/12', [
        'title' => 'Запланировать проверку доступности', 'description' => 'Обсудить дату с командой.',
        'status' => 'open', 'assignee_id' => 2, 'due_at' => null, 'escalated' => false, 'version' => 2,
    ]);
    check($status === 200 && $response['data']['escalated'] === 0, 'manager flag can be cleared');
    [$status, $response] = request('GET', '/api/projects');
    check(array_column($response['data'], 'escalation_count', 'id') === [1 => 1, 2 => 1, 3 => 0, 4 => 0], 'cleared flag leaves the counter');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
