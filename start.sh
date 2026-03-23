#!/bin/sh
echo "Starting PHP server on port $PORT"
exec php -S 0.0.0.0:$PORT -t .