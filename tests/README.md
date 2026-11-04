# Automated tests

Run from the project folder (XAMPP):

    C:\xampp\php\php.exe tests\run.php

What it does: builds a **separate** database called `<your DB_NAME>_test` (it refuses to touch any database whose
name does not end in `_test`), loads the schema and sample data, starts PHP's built-in web server on port 8099, runs
the checks over real HTTP, then stops the server and drops the test database. Your real database, `uploads/` and
cache are never used. It needs a MySQL user that may create databases (XAMPP's `root` with no password works).
It does not send real email or call Flutterwave.