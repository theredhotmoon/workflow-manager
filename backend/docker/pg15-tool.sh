#!/bin/sh
# Installed as /usr/local/bin/pg_dump and pg_restore (backend/Dockerfile). Runs the PostgreSQL
# 15 tool of the same name with its own libpq, kept off the default library path so it never
# replaces the libpq that pdo_pgsql uses.
LD_LIBRARY_PATH=/opt/pg15/lib exec "/opt/pg15/bin/$(basename "$0")" "$@"
