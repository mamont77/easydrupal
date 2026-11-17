#!/usr/bin/env python3
"""inspect_ai entry-point target — importing this registers the abp_cli provider.

Declared as the ``inspect_ai`` entry point in ``pyproject.toml`` so the native
``inspect eval --model abp_cli/...`` CLI discovers the provider at model
resolution (which happens before the task file is imported). ``get_model`` calls
``ensure_entry_points()``, which imports this module, whose import side effect is
the ``@modelapi("abp_cli")`` registration in ``provider``.

Uniquely named ON PURPOSE: the installed top-level module must not be a generic
name like ``provider`` that could collide with / be shadowed by another package
in a shared venv. ``provider`` itself is NOT installed as a global module; this
shim reaches it via a sys.path insert of its own directory, so the loose-script
``import provider`` used elsewhere (tasks.py / run.py / tests) still resolves to
the same local file.
"""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import provider  # noqa: F401,E402  importing runs @modelapi("abp_cli")
