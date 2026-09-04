# Deployment Guide — PandaStack

This document describes how to deploy WattPulse to [PandaStack](https://pandastack.com) using Docker containerization with a managed MySQL 8 database.

## Prerequisites

- A GitHub repository containing this project (already configured for Git deploy)
- A PandaStack account (free tier: 1 web service + 1 managed MySQL 8 database)

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│  PandaStack                                              │
│  ┌──────────────────────┐    ┌────────────────────────┐ │
│  │  Web Service (Docker) │───▶│  Managed MySQL 8       │ │
│  │  php:8.2-apache       │    │  (credentials via env) │ │
│  │  Port 80              │    └────────────────────────┘ │
│  └──────────────────────┘                                │
│  DocumentRoot: /var/www/html/public                      │
│  Persistent volume: ⚠️ SEE KNOWN LIMITATIONS BELOW       │
└─────────────────────────────────────────────────────────┘
```

## Step-by-Step Deployment

### 1. Connect GitHub Repository

1. Log in to the PandaStack dashboard.
2. Create a new **Web Service**.
3. Select **GitHub** as the deploy source.
4. Authorize PandaStack to access your repository.
5. Select the `EE` repository and `main` branch.
6. Choose **Docker** as the build method (the platform will auto-detect the `Dockerfile` in the project root).

### 2. Attach Managed MySQL Database

1. In the PandaStack dashboard, create a **Managed MySQL 8** database.
2. Attach it to your web service.
3. PandaStack will inject database credentials as environment variables into your container (typically `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` — confirm the exact variable names in your PandaStack dashboard after attachment).

### 3. Set Environment Variables

In the PandaStack dashboard, set the following environment variables for your web service:

| Variable | Description | Example |
|----------|-------------|---------|
| `DB_HOST` | Database host (auto-injected) | `db.pandastack.internal` |
| `DB_PORT` | Database port (auto-injected) | `3306` |
| `DB_USER` | Database username (auto-injected) | `wattpulse_user` |
| `DB_PASS` | Database password (auto-injected) | `••••••••` |
| `DB_NAME` | Database name (auto-injected) | `ev_charging_db` |
| `GMAIL_USER` | Gmail address for OTP emails | `yourapp@gmail.com` |
| `GMAIL_APP_PASSWORD` | Gmail App Password | `abcd efgh ijkl mnop` |
| `PAYMENT_DRIVER` | Payment mode | `simulated` |
| `KHALTI_SECRET_KEY` | Khalti API key (if live) | `••••••••` |

> **Note:** The app reads these from `$_ENV` via phpdotenv. If PandaStack injects them as platform environment variables, they will be available without a `.env` file. The `.env` file is only used for local development.

### 4. Deploy

1. Trigger the initial deploy from the PandaStack dashboard (or push to `main` if auto-deploy is enabled).
2. PandaStack will build the Docker image using the `Dockerfile` in the project root.
3. Once built, the container starts and Apache serves the app on port 80.

### 5. Initialize the Database Schema

After the first deploy, you need to import the database schema. Options:

- **Option A:** Connect to the managed MySQL database using a MySQL client (if PandaStack provides connection details) and import `database/schema.sql`.
- **Option B:** Temporarily run a migration script via an admin endpoint (if one exists in the app).

```bash
# Example (if direct MySQL access is provided):
mysql -h <DB_HOST> -u <DB_USER> -p<DB_PASS> <DB_NAME> < database/schema.sql
```

### 6. Verify

1. Visit the URL provided by PandaStack for your web service.
2. Confirm the landing page loads.
3. Test registration (OTP email requires valid Gmail credentials).
4. Test login and dashboard access.

## Persistent Storage — Profile Pictures

PandaStack supports **persistent volumes** for container apps. You must configure one so uploaded profile pictures survive redeploys and container restarts.

### What needs persisting

The app writes uploaded profile pictures to `public/assets/uploads/pfp/` (relative to the project root). Inside the container, this maps to:

```
/var/www/html/public/assets/uploads/pfp/
```

Without a persistent volume mounted here, **all uploaded profile pictures are lost on every redeploy**.

### Configuration steps

1. **In the PandaStack dashboard**, when creating (or editing) your web service, look for the **"Persistent Volume"** or **"Storage"** section.
2. **Create a persistent volume** (size: 1 GB is plenty for profile pictures; adjust if you expect high volume).
3. **Mount it** at the container path:

   | Setting | Value |
   |---------|-------|
   | Container mount path | `/var/www/html/public/assets/uploads/` |
   | Volume name | `wattpulse-uploads` (or any name you prefer) |

   > Mount at `public/assets/uploads/` (the parent of `pfp/`) so the entire uploads tree is covered. The `pfp/` subdirectory will be created automatically on first upload.

4. **Save and redeploy.** The volume is now attached. Files written to this path persist across deploys and restarts.

### Verification

After deploy, upload a profile picture via the app, then check the PandaStack dashboard for a "Restart" or "Redeploy" trigger — after restarting, the uploaded picture should still be visible.

### Other ephemeral paths (lower priority)

- **Sessions:** Stored on disk (`/tmp` by default). Sessions will be lost on container restart. For production, consider migrating to database-backed sessions or Redis (if PandaStack offers it).
- **Logs:** Application logs in `logs/` are also ephemeral. For production debugging, consider logging to `stdout`/`stderr` (visible in PandaStack's log viewer) instead of files.

## Local Docker Testing

To test the Docker setup locally before deploying:

```bash
# Build the image
docker build -t wattpulse .

# Run with environment variables (replace with your local DB values)
docker run -d \
  --name wattpulse \
  -p 8080:80 \
  -e DB_HOST=host.docker.internal \
  -e DB_PORT=3306 \
  -e DB_USER=root \
  -e DB_PASS= \
  -e DB_NAME=ev_charging_db \
  -e GMAIL_USER=yourapp@gmail.com \
  -e GMAIL_APP_PASSWORD=your-app-password \
  wattpulse

# Access the app at http://localhost:8080/
```

To test against a containerized MySQL:

```bash
# Start MySQL container
docker run -d \
  --name wattpulse-db \
  -e MYSQL_ROOT_PASSWORD=rootpass \
  -e MYSQL_DATABASE=ev_charging_db \
  -p 3307:3306 \
  mysql:8

# Import schema
mysql -h 127.0.0.1 -P 3307 -u root -prootpass ev_charging_db < database/schema.sql

# Run app container linked to DB
docker run -d \
  --name wattpulse \
  -p 8080:80 \
  -e DB_HOST=host.docker.internal \
  -e DB_PORT=3307 \
  -e DB_USER=root \
  -e DB_PASS=rootpass \
  -e DB_NAME=ev_charging_db \
  wattpulse
```

## Dockerfile Summary

- **Base image:** `php:8.2-apache` (matches local PHP 8.2.x)
- **PHP extensions:** `pdo_mysql`, `gd`, `mbstring`
- **Apache modules:** `mod_rewrite` enabled
- **DocumentRoot:** `/var/www/html/public`
- **Port:** 80
- **Multi-stage build:** Composer dependencies built in a separate stage, keeping the final image lean

