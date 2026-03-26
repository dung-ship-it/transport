#!/bin/sh
echo "Starting PHP server on port $PORT"
mkdir -p uploads/fuel_receipts
chmod -R 755 uploads
exec php -S 0.0.0.0:$PORT -t .