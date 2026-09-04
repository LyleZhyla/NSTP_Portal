# Project response preferences

These instructions apply to this project in every conversation.

- After completing project file changes, always include copy-paste-ready Windows CMD commands in the final response so the user can commit and push the changes to GitHub without asking again.
- Start the command block with `cd /d "C:\xampp\htdocs\qr-code-attendance-system-main"` (use the actual checkout path if it differs).
- Include `git add` with the specific files for the completed task, `git commit -m` with a descriptive message, and `git push`. Avoid `git add .` when unrelated changes are present.
- When relevant, provide `git push -u origin HEAD` as the fallback if the current branch has no upstream. Do not assume the branch is named main or master.
- Provide the commands as instructions for the user; this preference alone does not authorize executing a commit or push.
- For a question-only response with no project file changes, do not invent a commit; briefly say that there are no new changes to push if Git commands are relevant.
- Keep explanations concise and use Filipino/Taglish when the user does.
