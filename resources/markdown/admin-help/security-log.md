# Security log

Who signed in or was refused, which accounts and settings changed, who can
open which assessment, and who exported one: the events this instance
recorded in the last 90 days (the person running the server may set
another period). The tab shows the latest 50 matching events and downloads
all matching ones as **CSV** or **JSON**, filtered by date range (UTC days)
and event. A download holds at most 50,000 events and is itself recorded.

Rows here can be altered by anyone with access to the database. For a record
that cannot, send the server's standard error to a log collector. Application
errors are not shown here; read them on the server with
`docker compose logs app`.
