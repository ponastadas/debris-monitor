# SatView — Technical Documentation

> **satview.eu** — Real-time orbital debris risk monitoring platform.

---

## Documents

| # | Document | What it covers |
|---|----------|----------------|
| 1 | [Architecture Overview](01-architecture-overview.md) | System context, component diagram, technology stack |
| 2 | [Database Schema](02-database-schema.md) | ER diagram, every table, column definitions, indexes, relationships |
| 3 | [Backend API](03-backend-api.md) | All endpoints, auth model, request/response shapes, error envelope |
| 4 | [Frontend Architecture](04-frontend-architecture.md) | Component tree, routing, state management, localStorage keys |
| 5 | [Data Pipeline](05-data-pipeline.md) | Satellite sync, conjunction sync, SGP4 screening, scheduling |
| 6 | [Access Control & Entitlements](06-access-control.md) | Auth guards, plan tiers, middleware, quota enforcement |
| 7 | [CI/CD & Deployment](07-cicd-deployment.md) | GitHub Actions, image tagging, staging/production deploy flow |
| 8 | [Infrastructure](08-infrastructure.md) | Docker services, networking, volumes, nginx routing |

---

## Quick Reference

```
satview.eu
├── Frontend  React 18 SPA (Vite)       — Three.js globe, tracker, alerts
├── Backend   Laravel 11 API            — /api/* REST endpoints
├── Database  MySQL 8                   — 15 tables, 32K+ satellites
└── Proxy     Traefik (prod) / nginx (local)
```

All diagrams use [Mermaid](https://mermaid.js.org/) and render natively in GitHub, GitLab, and most Markdown viewers.
