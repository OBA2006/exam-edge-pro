# ExamEdge Pro

Enterprise AI-powered examination platform built with Laravel 12 and React 18.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.3), PostgreSQL 16, Redis 7 |
| Auth | JWT (tymon/jwt-auth) with TOTP 2FA |
| Queues | Laravel Horizon |
| WebSockets | Laravel Reverb |
| AI | Anthropic Claude API (claude-sonnet-4-20250514) |
| Frontend | React 18 + TypeScript + Vite |
| State | Zustand |
| Infra | Docker Compose (dev), Kubernetes (prod) |
| CI/CD | GitHub Actions → GHCR → kubectl |

## Features

- **Exam engine**: MCQ, essay, coding, true/false, fill-in question types with shuffle, attempt limits, and scheduling windows
- **AI grading**: Claude-powered essay and code evaluation with confidence-based auto-approval (≥92% confidence)
- **Proctoring**: Tab-switch detection, copy/paste blocking, fullscreen enforcement, webcam snapshots, risk scoring
- **Analytics**: Score distributions, question heatmaps, cohort analysis, AI-generated insights
- **Certificates**: SHA-256 hash-chained, publicly verifiable completion certificates
- **Gamification**: Badges (perfect score, first pass, top scorer, integrity streaks)
- **Webhooks**: HMAC-signed event delivery with retry/backoff
- **Audit log**: Full activity trail for compliance
- **Multi-tenant ready**: Tenant-scoped courses, exams, and users

## Quick Start (Docker)

```bash
git clone <repo>
cd examedge-pro
cp .env.example .env
# Edit .env: set DB_PASSWORD, REDIS_PASSWORD, JWT_SECRET, ANTHROPIC_API_KEY

docker-compose up -d --build

# Run migrations and seed demo data
docker-compose exec backend php artisan migrate --seed

# Generate JWT secret
docker-compose exec backend php artisan jwt:secret
```

Frontend: http://localhost:3000
Backend API: http://localhost:8000/api/v1
Health check: http://localhost:8000/api/v1/health

## Demo Credentials

All seeded accounts use password: `password`

| Role | Email |
|---|---|
| Admin | admin@examedge.pro |
| Instructor | instructor@examedge.pro |
| Student | alice@student.edu |
| Student | marcus@student.edu |

Demo exam: **CS401 Algorithms Final** (8 questions, 90 min, published)

## Project Structure

```
examedge-pro/
├── backend/                  # Laravel 12 API
│   ├── app/
│   │   ├── Http/Controllers/ # 15 controllers
│   │   ├── Http/Middleware/  # Role-based access, security headers
│   │   ├── Models/           # 18 Eloquent models
│   │   ├── Services/         # AiService, GradingService, etc.
│   │   └── Jobs/             # GradeEssaysJob, WebhookDeliveryJob, etc.
│   ├── database/
│   │   ├── migrations/       # 21-table schema
│   │   └── seeders/          # Demo data
│   └── routes/api.php        # ~80 REST endpoints
├── frontend/                 # React 18 + TypeScript
│   └── src/
│       ├── components/       # AppShell layout
│       ├── pages/            # Login, Dashboard, Exams, ExamTaking
│       ├── store/            # Zustand state (auth, exam, session, notifications)
│       ├── hooks/             # useExamTimer, useProctoringEngine, etc.
│       ├── utils/api.ts      # Axios client with JWT refresh
│       └── types/            # TypeScript interfaces
├── k8s/all-manifests.yaml    # Production Kubernetes deployment
├── .github/workflows/        # CI/CD pipeline
└── docker-compose.yml        # Local development stack
```

## Production Deployment

```bash
kubectl apply -f k8s/all-manifests.yaml
kubectl exec -n examedge deployment/examedge-backend -- php artisan migrate --force
```

The HPA scales the backend from 3→20 pods at 70% CPU utilization, and Horizon workers from 2→10 at 75% CPU.

## License

MIT
