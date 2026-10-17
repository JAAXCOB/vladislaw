# Project-local design skills

These skills are used only while working on AV Rescue UI. They do not run in
production and are not part of the Android or server runtime.

| Skill | Upstream | License | AV Rescue scope |
| --- | --- | --- | --- |
| `impeccable` | https://github.com/pbakaus/impeccable | Apache-2.0 | Baseline UI/UX, responsive and accessibility audit |
| `gpt-taste` | https://github.com/Leonxlnx/taste-skill | MIT, Copyright (c) 2026 Leonxlnx | Public marketing surfaces only |
| `emil-design-eng` | https://github.com/emilkowalski/skills | MIT, Copyright (c) 2026 Emil Kowalski | Restrained micro-interactions and motion review |

The Impeccable launcher can download a version-pinned executable when invoked.
Do not run that launcher or enable hooks automatically. Review and approve the
exact version and checksum path before first execution. The static guidance may
be used without running the launcher.

`PRODUCT.md` and `DESIGN.md` in the repository root override generic advice from
these third-party skills wherever AV Rescue operational safety or workflow
clarity requires it.
