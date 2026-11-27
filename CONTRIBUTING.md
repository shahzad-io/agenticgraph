# Contributing

Thank you for helping. This guide tells you how to send a change.

## Before you start

- For a bug, open an issue with the bug template first, unless the fix is very small.
- For a new feature, open an issue with the feature template. Agree on the design before you write the code.
- For a security problem, do not open an issue. Read [SECURITY.md](SECURITY.md).

## Gates

Run these commands before you open a pull request. All of them must pass.

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

PHP 8.3+. A test that calls a real provider (for example `NodeSeqCoherenceTest`) runs only when its key is set (`OPENAI_API_KEY`).

Some tests are off by default:

- `AGENTICGRAPH_TEST_REDIS_PORT`: the port of a Redis for the Redis storage cases in `DeletedRunTest`. **Warning: these cases run `FLUSHDB`, which deletes every key in the database. Use only a disposable Redis. Do not use a Redis that holds data you want to keep.** For example, start one with `docker run --rm -d -p 6390:6379 redis:7` and set the variable to `6390`.
- `AGENTICGRAPH_LOAD_TEST=true`: runs the load test in `ExecutionSpeedTest` (100 streams with 10,000 events each).

## Rules for code and text

- Add a test that fails without your change and passes with it.
- Do not use em-dashes (the character U+2014) in code, comments, tests, docs or commit messages. Use a colon, a comma, parentheses or a new sentence.
- Write docs, comments and messages in Simplified Technical English: short sentences, one instruction in each sentence, active voice, and the same word for the same thing.
- If you change public behavior, add a line to `CHANGELOG.md` under the unreleased version.
- Keep a pull request to one change.

## Pull requests

1. Fork the repository and make a branch from `main`.
2. Make your change and run the gates.
3. Open a pull request. Tell what changed and why, and link the issue.
