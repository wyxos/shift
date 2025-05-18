# SHIFT - Task Management System

SHIFT is a comprehensive task management system built with Laravel and Vue.js, designed to help teams organize and track their projects and tasks efficiently.

## Overview

SHIFT consists of two main components:
1. **Dashboard** - A web application for managing clients, projects, and tasks
2. **SDK** - A package that allows integration with other Laravel applications

## Features

### Dashboard
- **Organization Management**: Create and manage organizations
- **Client Management**: Organize clients within organizations
- **Project Management**: Create and manage projects for clients
- **Task Management**: Create, update, and track tasks within projects
- **Task Prioritization**: Set and toggle task priorities
- **Task Status Tracking**: Mark tasks as complete/incomplete

### SDK Integration
- Connect external Laravel applications to the SHIFT dashboard
- Create and manage tasks directly from integrated applications
- View tasks created from integrated applications

## Technical Stack

- **Backend**: Laravel PHP framework
- **Frontend**: Vue.js with Inertia.js
- **Authentication**: Laravel Sanctum
- **UI**: Tailwind CSS

## Project Structure

The application follows a hierarchical data structure:
- Organizations have many Clients
- Clients have many Projects
- Projects have many Tasks
- Projects can have multiple Users (team members)

## Development Status

The project is currently in active development with most of the core dashboard features implemented. The SDK integration is partially complete with ongoing work to enhance its capabilities.

### Completed Features
- Client CRUD operations
- Project CRUD operations
- Task CRUD operations
- REST API for Tasks/Projects
- Basic setup command for SDK integration

### Planned Features
- File attachments to tasks
- Rich comments on tasks
- Sub-tasks / Checklists
- Project analytics
- OAuth login / External user accounts
- Notifications (email/slack/push)

## Getting Started

To get started with SHIFT, follow these steps:

1. Clone the repository
2. Install dependencies with `composer install` and `npm install`
3. Configure your environment variables
4. Run migrations with `php artisan migrate`
5. Start the development server with `php artisan serve`
6. Build frontend assets with `npm run dev`

## License

[License information would go here]
