<?php
declare(strict_types=1);

session_start();

const POLL_DATA_FILE = __DIR__ . '/data/polls.json';
const POLL_OPTIONS = ['Support', 'Oppose'];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validUsername(string $username): bool
{
    return preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]{0,31}$/u', $username) === 1;
}

function readAllowedVoters(): array
{
    $config = require __DIR__ . '/data/config.php';
    if (!is_array($config) || !isset($config['allowed_voters']) || !is_array($config['allowed_voters'])) {
        throw new RuntimeException('The voter configuration must return an allowed_voters array.');
    }

    foreach ($config['allowed_voters'] as $allowedUsername) {
        if (!is_string($allowedUsername) || !validUsername($allowedUsername)) {
            throw new RuntimeException('The voter configuration contains an invalid username.');
        }
    }

    return array_values(array_unique($config['allowed_voters']));
}

function textLength(string $text): int
{
    $length = preg_match_all('/./us', $text);
    if ($length === false) {
        throw new InvalidArgumentException('Text must use valid UTF-8 characters.');
    }

    return $length;
}

function readPollsFromHandle($handle): array
{
    if (!rewind($handle)) {
        throw new RuntimeException('Could not read the poll data file.');
    }

    $contents = stream_get_contents($handle);
    if ($contents === false) {
        throw new RuntimeException('Could not read the poll data file.');
    }

    if (trim($contents) === '') {
        return [];
    }

    try {
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The poll data file contains invalid JSON.', 0, $exception);
    }

    if (!is_array($data) || !isset($data['polls']) || !is_array($data['polls'])) {
        throw new RuntimeException('The poll data file has an invalid format.');
    }

    return $data['polls'];
}

function readPolls(): array
{
    $handle = fopen(POLL_DATA_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not open the poll data file. Check the hosting file permissions.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Could not lock the poll data file for reading.');
        }

        return readPollsFromHandle($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function updatePolls(callable $change): void
{
    $handle = fopen(POLL_DATA_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open the poll data file. Check the hosting file permissions.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the poll data file for writing.');
        }

        $polls = readPollsFromHandle($handle);
        $change($polls);
        $json = json_encode(['polls' => array_values($polls)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (!rewind($handle) || !ftruncate($handle, 0)) {
            throw new RuntimeException('Could not save poll data. Check the hosting file permissions and available disk space.');
        }
        $remaining = $json . PHP_EOL;
        while ($remaining !== '') {
            $written = fwrite($handle, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not save poll data. Check the hosting file permissions and available disk space.');
            }
            $remaining = substr($remaining, $written);
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Could not save poll data. Check the hosting file permissions and available disk space.');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function findPollIndex(array $polls, string $id): ?int
{
    foreach ($polls as $index => $poll) {
        if (isset($poll['id']) && hash_equals((string) $poll['id'], $id)) {
            return $index;
        }
    }

    return null;
}

function postedUsername(): string
{
    $username = trim((string) ($_POST['username'] ?? ''));
    if (!validUsername($username)) {
        throw new InvalidArgumentException('Enter a username of 1–32 letters, numbers, spaces, dots, dashes, or underscores. It must start with a letter or number.');
    }

    return $username;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$username = trim((string) ($_GET['user'] ?? ''));
if (!validUsername($username)) {
    $username = '';
}

$configError = '';
try {
    $allowedVoters = readAllowedVoters();
} catch (Throwable $exception) {
    $allowedVoters = [];
    $configError = $exception->getMessage();
}

$error = $configError;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));

    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string) $_POST['csrf_token'])) {
            throw new RuntimeException('Your form session expired. Please try again.');
        }

        $username = postedUsername();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'vote' || $action === 'open') {
            if ($configError !== '') {
                throw new RuntimeException('This action is unavailable because the voter configuration could not be loaded.');
            }
            if (!in_array($username, $allowedVoters, true)) {
                throw new InvalidArgumentException('Only usernames in the voter configuration can ' . ($action === 'open' ? 'open polls.' : 'vote.'));
            }
        }

        if ($action === 'open') {
            $question = trim((string) ($_POST['question'] ?? ''));
            if ($question === '' || textLength($question) > 240) {
                throw new InvalidArgumentException('The poll question must be between 1 and 240 characters.');
            }

            updatePolls(function (array &$polls) use ($question, $username): void {
                foreach ($polls as $poll) {
                    if (($poll['status'] ?? '') === 'active') {
                        throw new InvalidArgumentException('Close the active poll before opening another.');
                    }
                }

                $polls[] = [
                    'id' => bin2hex(random_bytes(12)),
                    'question' => $question,
                    'options' => POLL_OPTIONS,
                    'status' => 'active',
                    'votes' => [],
                    'created_at' => time(),
                    'opened_by' => $username,
                    'opened_at' => time(),
                ];
            });
            $_SESSION['flash'] = 'Poll opened. Players can now vote.';
        } elseif ($action === 'close' || $action === 'vote') {
            $pollId = (string) ($_POST['poll_id'] ?? '');
            updatePolls(function (array &$polls) use ($action, $pollId, $username): void {
                $index = findPollIndex($polls, $pollId);
                if ($index === null) {
                    throw new InvalidArgumentException('That poll could not be found.');
                }

                if ($action === 'close') {
                    if (($polls[$index]['status'] ?? '') !== 'active') {
                        throw new InvalidArgumentException('That poll is not active.');
                    }
                    $polls[$index]['status'] = 'closed';
                    $polls[$index]['closed_by'] = $username;
                    $polls[$index]['closed_at'] = time();
                } else {
                    if (($polls[$index]['status'] ?? '') !== 'active') {
                        throw new InvalidArgumentException('Voting is only available while a poll is active.');
                    }
                    $openedBy = $polls[$index]['opened_by'] ?? $polls[$index]['proposed_by'] ?? null;
                    if ($openedBy === $username) {
                        throw new InvalidArgumentException('The user who opened this poll cannot vote in it.');
                    }
                    $optionIndex = filter_var($_POST['option'] ?? null, FILTER_VALIDATE_INT);
                    if ($optionIndex === false || !isset($polls[$index]['options'][$optionIndex])) {
                        throw new InvalidArgumentException('Choose one of the listed answer options.');
                    }
                    foreach ($polls[$index]['votes'] as $vote) {
                        if (($vote['username'] ?? null) === $username) {
                            throw new InvalidArgumentException('This username has already voted in this poll.');
                        }
                    }

                    $polls[$index]['votes'][] = ['username' => $username, 'option' => $optionIndex];
                }
            });

            $_SESSION['flash'] = $action === 'close'
                ? 'Poll closed. Results are now visible.'
                : 'Your vote has been recorded.';
        } else {
            throw new InvalidArgumentException('Unknown form action.');
        }

        $redirect = 'index.php';
        if ($username !== '') {
            $redirect .= '?user=' . rawurlencode($username);
        }
        header('Location: ' . $redirect, true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$flash = (string) ($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

try {
    $polls = readPolls();
} catch (Throwable $exception) {
    $polls = [];
    $error = $error !== '' ? $error : $exception->getMessage();
}

$activePoll = null;
$closedPolls = [];
foreach ($polls as $poll) {
    if (($poll['status'] ?? '') === 'active') {
        $activePoll = $poll;
    } elseif (($poll['status'] ?? '') === 'closed') {
        $closedPolls[] = $poll;
    }
}
$closedPolls = array_reverse($closedPolls);

function renderVoteCount(array $poll, int $optionIndex): int
{
    return count(array_filter(
        $poll['votes'] ?? [],
        static function (array $vote) use ($optionIndex): bool {
            return (int) ($vote['option'] ?? -1) === $optionIndex;
        }
    ));
}

function renderDate($timestamp): string
{
    return date('M j, Y \a\t H:i', (int) $timestamp);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nomic Voting</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #202a35; background: #f3f6fa; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { padding: 2rem 1rem 1.5rem; color: white; background: #2456a6; }
        header > div, main { width: min(760px, 100%); margin: 0 auto; }
        h1 { margin: 0; font-size: 1.8rem; }
        header p { margin: .4rem 0 0; color: #e1eaff; }
        main { padding: 1rem; }
        section, .notice { margin: 0 0 1rem; padding: 1.25rem; border: 1px solid #dce3ed; border-radius: 12px; background: white; }
        h2 { margin: 0 0 1rem; font-size: 1.2rem; }
        h3 { margin: 0 0 .4rem; font-size: 1.05rem; }
        p { line-height: 1.5; }
        .meta, .hint { color: #637083; font-size: .9rem; }
        label { display: block; margin: .75rem 0 .3rem; font-weight: 600; }
        input[type="text"], input[type="number"] { width: 100%; padding: .65rem .75rem; border: 1px solid #bec9d8; border-radius: 7px; font: inherit; }
        .option-row { margin: .5rem 0; }
        button { margin-top: .9rem; padding: .65rem 1rem; border: 0; border-radius: 7px; color: white; background: #2456a6; font: inherit; font-weight: 650; cursor: pointer; }
        button:hover { background: #173f80; }
        .secondary { background: #46566b; }
        .notice { border-color: #b7d0f8; background: #edf4ff; }
        .error { border-color: #efb6b6; color: #7d1717; background: #fff0f0; }
        .choice { display: flex; gap: .6rem; align-items: flex-start; margin: .7rem 0; }
        .choice input { margin-top: .35rem; }
        .choice label { margin: 0; font-weight: 400; }
        .result { margin: .75rem 0; }
        .result-line { display: flex; justify-content: space-between; gap: .75rem; }
        .bar { height: 8px; margin-top: .3rem; overflow: hidden; border-radius: 8px; background: #e8edf4; }
        .bar span { display: block; height: 100%; background: #4a79c4; }
        .empty { color: #637083; }
        .history article + article { margin-top: 1.2rem; padding-top: 1rem; border-top: 1px solid #e4e9f0; }
        @media (max-width: 480px) { section, .notice { padding: 1rem; } }
    </style>
</head>
<body>
<header>
    <div>
        <h1>Nomic Voting</h1>
        <p>Open a poll for voting, then close it to reveal the results.</p>
    </div>
</header>
<main>
    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($flash !== ''): ?>
        <div class="notice" role="status"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($activePoll !== null): ?>
        <section>
            <h2>Active poll</h2>
            <h3><?= h((string) $activePoll['question']) ?></h3>
            <p class="meta">Opened by <?= h((string) ($activePoll['opened_by'] ?? $activePoll['proposed_by'] ?? '')) ?></p>
            <p class="meta"><?= count($activePoll['votes'] ?? []) ?> <?= count($activePoll['votes'] ?? []) === 1 ? 'user has' : 'users have' ?> voted</p>
            <?php if ($allowedVoters === []): ?>
                <p class="hint">No usernames are currently allowed to vote. Add usernames to <code>data/config.php</code>.</p>
            <?php endif; ?>
            <?php if ($username === '' || $username !== (string) ($activePoll['opened_by'] ?? $activePoll['proposed_by'] ?? '')): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="vote">
                    <input type="hidden" name="poll_id" value="<?= h((string) $activePoll['id']) ?>">
                    <label for="active-username">Your username</label>
                    <input id="active-username" type="text" name="username" maxlength="32" required value="<?= h($username) ?>" placeholder="e.g. Alex">
                    <?php foreach ($activePoll['options'] as $optionIndex => $option): ?>
                        <div class="choice">
                            <input id="option-<?= $optionIndex ?>" type="radio" name="option" value="<?= $optionIndex ?>" required>
                            <label for="option-<?= $optionIndex ?>"><?= h((string) $option) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <p class="hint">Only usernames in the voter configuration can vote. The user who opened this poll cannot vote in it. Each other allowed username can vote once and cannot change their vote. Results stay hidden until the poll is closed.</p>
                    <button type="submit">Submit vote</button>
                </form>
            <?php else: ?>
                <p class="hint">You opened this poll, so you cannot vote in it.</p>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="poll_id" value="<?= h((string) $activePoll['id']) ?>">
                <label for="close-username">Close this poll as</label>
                <input id="close-username" type="text" name="username" maxlength="32" required value="<?= h($username) ?>" placeholder="Your username">
                <button class="secondary" type="submit">Close poll and reveal results</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($activePoll === null): ?>
        <section>
            <h2>Open a new poll</h2>
            <?php if ($allowedVoters === []): ?>
                <p class="hint">No usernames are currently allowed to open polls. Add usernames to <code>data/config.php</code>.</p>
            <?php else: ?>
                <p class="hint">Only allowed voters can open a poll. Opening it makes it active immediately. Answer options are Support and Oppose.</p>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="open">
                <label for="opener">Your username</label>
                <input id="opener" type="text" name="username" maxlength="32" required value="<?= h($username) ?>" placeholder="e.g. Alex">
                <label for="question">Question</label>
                <input id="question" type="text" name="question" maxlength="240" required placeholder="What should we decide?">
                <button type="submit">Open poll</button>
            </form>
        </section>
        <?php else: ?>
            <p class="hint">Close the active poll to open the next one.</p>
        <?php endif; ?>

    <section class="history">
        <h2>Previous polls</h2>
        <?php if ($closedPolls === []): ?>
            <p class="empty">Closed polls and their results will appear here.</p>
        <?php else: ?>
            <?php foreach ($closedPolls as $poll): ?>
                <?php $totalVotes = count($poll['votes'] ?? []); ?>
                <article>
                    <h3><?= h((string) $poll['question']) ?></h3>
                    <p class="meta">
                        <?= isset($poll['opened_by']) ? 'Opened by ' . h((string) $poll['opened_by']) : 'Proposed by ' . h((string) ($poll['proposed_by'] ?? '')) ?>
                        · Closed by <?= h((string) ($poll['closed_by'] ?? '')) ?> · <?= h(renderDate($poll['closed_at'] ?? time())) ?>
                    </p>
                    <?php foreach ($poll['options'] as $optionIndex => $option): ?>
                        <?php $count = renderVoteCount($poll, $optionIndex); ?>
                        <?php $percentage = $totalVotes === 0 ? 0 : (int) round($count * 100 / $totalVotes); ?>
                        <div class="result">
                            <div class="result-line"><span><?= h((string) $option) ?></span><span><?= $count ?> (<?= $percentage ?>%)</span></div>
                            <div class="bar" aria-hidden="true"><span style="width: <?= $percentage ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                    <p class="meta"><?= $totalVotes ?> <?= $totalVotes === 1 ? 'vote' : 'votes' ?></p>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
