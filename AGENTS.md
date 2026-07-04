# Project Notes

- When changing plugin behavior, UI, delivery integrations, or any user-visible feature, update `metadata.json`'s `version` so plugin managers can detect the update.
- Keep the version monotonic and use semantic versioning: patch for fixes, minor for new compatible features, major for breaking changes.
- After finishing a coherent feature or user-visible change, create one commit and push it to the user's fork so the plugin manager can pick up the update promptly.
