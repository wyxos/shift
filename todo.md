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
- [ ] Add/Read Comments on Tasks (basic textarea)
- [x] Implement REST API for Tasks/Projects (read/write)

---

**SDK MVP**
- [ ] Connect Laravel app to SHIFT dashboard via config (`SHIFT_API_TOKEN`, `SHIFT_PROJECT_ID`, etc.)
- [ ] Create `php artisan shift:setup` command:
    - [ ] Prompt for API key
    - [ ] Test connection to SHIFT
    - [ ] Search or create a Project
    - [ ] Save config locally
- [ ] SDK exposes `/shift` route:
    - [ ] User can view tasks they created
    - [ ] User can create/edit their own tasks (sent to SHIFT backend)

---

**Nice-to-Have After MVP**
- [ ] File attachments to tasks (upload images/files)
- [ ] Threaded, rich comments on tasks
- [ ] Sub-tasks / Checklists inside tasks
- [ ] Advanced grouping (URLs, branches, etc)
- [ ] Project analytics (tasks open/closed stats)
- [ ] OAuth login / External user accounts
- [ ] Notifications (email/slack/push)
