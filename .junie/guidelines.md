General guidelines:

- Always use Laravel ```php artisan``` command to scaffold relevant class for the task.
- Ensure to remove any logging statements you added before completing a task.
- Favor type hints and type declaration for php code.
- Ensure all javascript code contain type hints.
- For any UI alignment, verify if backend alignments are also needed and vice versa.
- This project uses the PEST testing framework for PHP tests, so ensure all tests are written using PEST syntax.
- After completing a task involving PHP logic, create a new PEST test case if it doesn't exists, and evaluate the test
  individually ensuring it passes. If a test case exists, align it accordingly.
- Run the full ```php artisan test``` after completing a task to ensure all tests pass.
- Never make assumptions or use placeholders. If in doubt, ask for clarification or halt the task with a comment.
- Any created command/logic that deals with looping through a consequent amount of data or performs intensive disk
  operations should be queued in a job to avoid blocking the main thread.
- Never use Model::all() or similar methods that load entire datasets into memory; always use chunking (Model::chunk())
  for processing large collections.
- Always use Inertia router where applicable if present. Otherwise favor axios and never use fetch.
- Do not create custom files to test functionalities. Use test cases as per project test framework.
- Use laravel.log and Log class to debug issues, and ensure to remove any logging statements and to clear the
  laravel.log file before completing the task.
- For details about @wyxos/vision component, navigate to ../../../wyxos/js/vision.

Project specific guidelines:

- After completing each task, update this guidelines.md file if you've made changes that affect the project structure,
  workflow, or best practices
- When implementing new features, document them in this file if they introduce new patterns or technologies
- Keep this file concise and focused on information that will help with future development tasks

