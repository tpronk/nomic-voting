# Simple Poll

A small, database-free polling site for PHP shared hosting. Polls are stored in
`data/polls.json`; the active poll, votes, and closed poll results are kept
together in that file.

## Install

1. Upload the files and `data` directory to a PHP-enabled web host.
2. Make `data/polls.json` writable by the PHP process. The application uses
   file locks so simultaneous votes and poll actions are serialized.
3. Add the exact usernames allowed to vote to the `allowed_voters` array in
   `data/config.php`. With an empty list, nobody can vote or open polls.
4. Visit the site in a browser. No database or package installation is needed.

Use PHP 7.4 or newer. The included `data/.htaccess` prevents direct downloads of
the poll data on Apache hosts. Keep the data directory outside the public web
root if your host allows it; otherwise retain the access restriction and do
not remove the `.htaccess` file.

## Use

- Add `?user=YourName` to the URL to prefill the username fields.
- When no poll is active, an allowed username can enter a question and open a
  poll immediately. Its answer options are always Support and Oppose. There is
  no queue of proposed polls.
- Only one poll can be active at a time. Voting is available while it is open.
- Close the active poll to reveal its results; then an allowed username can
  open and configure the next poll.
- The active poll shows how many users have voted. After closing, results show
  anonymous counts only. Voter usernames remain in `data/polls.json` so the
  app can enforce one vote per allowed username per poll.
- The username that opened a poll cannot vote in it. Other allowed usernames
  can vote only once per poll and cannot change that vote.
- Results are shown only after a poll is closed, and remain in Previous polls.

Usernames are labels, not accounts: the allowlist does not authenticate a
person or prevent someone from using another allowed username. Usernames must
match the configured spelling exactly.
