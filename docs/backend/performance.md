# Speed and scale (Round 2, phases 5 and 6)

Measured on 300,000 vote orders, 30,000 ticket orders, 200,000 audit rows, 100,000 outbox rows
(single CPU core, Apache + mod_php, MariaDB on the same machine).

- A list is paged when the request has `?page=`. Parameters: `page`, `perPage` (5-500), `q`, `sort`, `dir`, plus one
  parameter per filter. Sort names come from a whitelist.
- Nothing searches while you type. Every search box has a Search button (and Enter).
- Sorting by nominee or category name in the vote ledger is switched off: it cannot use an index.
- `vote_totals` is updated inside the transaction that credits, adds or voids votes. Run
  `php backend/tools/rebuild-vote-totals.php` nightly from cron (add `--fix` to repair).
- Public data: file cache plus ETag/304; only one request rebuilds an expired entry. Gzip via Apache and PHP.
- Load test: `php tests/load.php https://v2.ogaaward.xyz --users=100 --seconds=30`
  (add OGA_LOAD_EMAIL / OGA_LOAD_PASSWORD in the environment to include the admin lists).
- VPS later: raise PHP-FPM workers, enable OPcache, size `innodb_buffer_pool_size` to 50-70% of RAM; the file
  cache can move to Redis by replacing `Cache::remember`.
