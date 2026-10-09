<?php

namespace App\Domain\Snapshots;

use RuntimeException;

/**
 * A snapshot this code will not apply, for a reason the person who pressed the
 * button can understand and act on: it belongs to another assessment, or it was
 * written in a `schemaVersion` this build does not know how to read.
 *
 * ## Why this exists rather than a bare RuntimeException
 *
 * SnapshotController shows the message of whatever it catches. W61 caught
 * `RuntimeException`, which looked narrow and is not: **`QueryException`
 * extends `PDOException` extends `RuntimeException`**, so any database error
 * inside a restore was caught by that block and its message — the failing SQL
 * and its bound values, which for this path means node titles, descriptions and
 * user ids — was rendered into a flash message on the page.
 *
 * That was found by mutation-testing something else in W62: neutering an
 * unrelated guard produced a foreign-key violation, and the test stayed green
 * because the resulting 500 had been quietly converted into a friendly-looking
 * error banner. A test that cannot go red is not the worst of it; the leak is.
 *
 * So the two cases are separated by type. This one is expected, is the caller's
 * to explain, and its message is written to be read by a user. A
 * `QueryException` is a defect in this code, must not be dressed up as a
 * handled outcome, and now reaches the error handler like any other bug —
 * where security.md's rule about never showing internals applies to it.
 */
final class SnapshotNotRestorableException extends RuntimeException {}
