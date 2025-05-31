**Dashboard MVP**

- [x] List Clients
- [x] Create Clients
- [x] Update Clients
- [x] List Projects
- [x] Create Projects
- [x] Update Projects
- [x] List Tasks
- [x] Create Tasks
- [x] Update Tasks
- [x] Add/Read Comments on Tasks (basic textarea)
- [x] Implement REST API for Tasks/Projects (read/write)

---

**SDK MVP**

- [x] Connect Laravel app to SHIFT dashboard via config (`SHIFT_TOKEN`, `SHIFT_PROJECT`, etc.)
- [x] Create `php artisan shift:install` command:
    - [x] Prompt for API key
    - [x] Test connection to SHIFT
    - [ ] Search or create a Project
    - [x] Save config locally
- [x] SDK exposes `/shift` route:
    - [x] User can view tasks they created
    - [x] User can create/edit their own tasks (sent to SHIFT backend)

---

**Nice-to-Have After MVP**

- [x] File attachments to tasks (upload images/files)
- [x] Threaded, rich comments on tasks
- [ ] Sub-tasks / Checklists inside tasks
- [ ] Advanced grouping (URLs, branches, etc)
- [ ] Project analytics (tasks open/closed stats)
- [ ] OAuth login / External user accounts
- [ ] Notifications (email/slack/push)
- [ ] Git integration
