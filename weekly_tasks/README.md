# Weekly Performance Tracking Module

> **Full module guide (launcher, missions, analytics, setup):** [modules/performance/README.md](../modules/performance/README.md)

This module implements an **automated, "blind" performance scoring system** for employee tasks.

## Core Concept: Hidden Weights
Employees plan their week simply by describing their tasks. They do **not** select weights or difficulty levels.
The system automatically assigns a hidden score (1-5) to each task based on a dictionary of **1000+ keywords** tailored to their department (Sales, IT, Drivers, Procurement, Finance).

## Key Components

### 1. Team Dashboard (`index.php`)
- **Company-Wide View**: Displays a grid of user cards.
- **Performance Bar**: Visualizes completion percentage based on *weighted* points (not just task count).
- **Leaderboard**: Color-coded (Green > 80%, Blue 50-79%, Orange < 50%).

### 2. Task Planning (`plan.php`)
- **Zero Friction**: Simple text inputs for major weekly tasks.
- **Auto-Scoring Engine**: Upon submission, `includes/task_scoring.php` analyzes descriptions and assigns scores.
    - *Example*: "Negotiate new contract" (Sales) -> 5 points.
    - *Example*: "Reply to emails" -> 1 point.
- **Department Aware**: Scoring dictionaries are specific to the user's department.

### 3. Execution & Progress (`view_plan.php`)
- **Individual Drill-Down**: Click any card on the dashboard to view that user's plan.
- **Green Circle Interaction**: Employees complete tasks by clicking a circle, which turns green.
- **Permissions**: You can only check off tasks on your *own* plan. Others are read-only.

### 4. API & Logic
- `api_toggle.php`: Handles AJAX requests to mark tasks as complete/incomplete.
- `../includes/task_scoring.php`: The core heuristic engine containing keyword arrays.

## Database Schema
Two main tables drive this module:
1.  **`weekly_plans`**: Stores the plan metadata (user, week start date, status).
2.  **`weekly_plan_items`**: Stores individual tasks, their description, completion status, and the **auto-calculated weight**.

## Setup
Ensure the `weekly_plans` and `weekly_plan_items` tables are created. If missing, run `fix_weekly_tasks_schema.php`.
