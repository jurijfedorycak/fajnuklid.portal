The app is a web client portal of a cleaning company called Fajnuklid. Each client can see their data, contracts, who and when cleaned, contacts, complaints and all other useful information. Its supposed to act as a sales channel, to convince customers this is the right company to choose.

# Architecture

Backend: PHP REST API using without a framework, using custom router, controller and service architecture
Database: MySQL, direct SQL queries
Frontend: Vue.JS 3 using latest and most modern approach. Fontawesome icons. Validate each form on both FE and BE.
App Language: Czech, but use English URL routing

## App modes

1. client, who see their data only, needs GREAT UI UX
2. administrator, who manages clients

# Rules

1. Never commit anything, it must be reviewed by another developer first
2. Dont use HTML alerts
3. Always use CSS variables from `web/src/style.css` for colors, never hardcode color values.
4. Create a custom unique ID to each visual HTML element

# Critical

1. After each code change run a subagent code review. Address all provided feedback. Repeat until code review is clean.
2. Maintain DB schema in files database-schema.drawio and schema.sql with each DB migration.
3. For good API performance, always create exactly 1 API controller for each page, tab or modal. This single controller will serve all required data on that specific page. Load only required data, dont preload anything further.
