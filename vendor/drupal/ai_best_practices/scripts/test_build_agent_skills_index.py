#!/usr/bin/env python3
"""Fixture tests for scripts/build-agent-skills-index.py (stdlib unittest)."""

from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent / "build-agent-skills-index.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("build_agent_skills_index", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"cannot load {SCRIPT}")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


MOD = load_mod()


def write_skill(directory: Path, name: str, body: str = "") -> None:
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "SKILL.md").write_text(
        f"---\nname: {name}\ndescription: Test skill {name}.\n---\n\n{body}",
        encoding="utf-8",
    )


class ArchiveMemberTests(unittest.TestCase):
    def test_nested_skill_is_catalogued_and_bundled_in_parent(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "skills"
            parent = root / "parent-skill"
            child = parent / "child-skill"
            write_skill(
                parent,
                "parent-skill",
                "Load [child](child-skill/SKILL.md).\n",
            )
            write_skill(child, "child-skill", "Child body.\n")
            (child / "SYNC.md").write_text("maintainer\n", encoding="utf-8")

            skills = MOD.discover_skills(root)
            names = {s.name for s in skills}
            self.assertEqual(names, {"parent-skill", "child-skill"})

            by_name = {s.name: s for s in skills}
            parent_members = {n for n, _ in MOD.collect_archive_members(by_name["parent-skill"])}
            child_members = {n for n, _ in MOD.collect_archive_members(by_name["child-skill"])}

            self.assertIn("SKILL.md", parent_members)
            self.assertIn("child-skill/SKILL.md", parent_members)
            self.assertNotIn("child-skill/SYNC.md", parent_members)
            self.assertEqual(child_members, {"SKILL.md"})

            out = Path(tmp) / "site"
            MOD.build_catalog(skills, out)

            parent_archive = out / ".well-known" / "agent-skills" / "parent-skill.tar.gz"
            archived = MOD.archive_member_names(parent_archive)
            self.assertIn("child-skill/SKILL.md", archived)

    def test_symlink_member_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            outside = tmp_path / "secret.txt"
            outside.write_text("nope\n", encoding="utf-8")
            root = tmp_path / "skills"
            skill_dir = root / "plain-skill"
            write_skill(skill_dir, "plain-skill")
            (skill_dir / "leak").symlink_to(outside)

            skills = MOD.discover_skills(root)
            self.assertEqual(len(skills), 1)
            with self.assertRaises(SystemExit) as ctx:
                MOD.collect_archive_members(skills[0])
            self.assertIn("symlink", str(ctx.exception).lower())

    def test_readme_is_packed_when_skill_md_references_it(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "skills"
            skill_dir = root / "gitlab-like"
            write_skill(
                skill_dir,
                "gitlab-like",
                "See `README.md` for setup.\n",
            )
            (skill_dir / "README.md").write_text("setup\n", encoding="utf-8")
            skills = MOD.discover_skills(root)
            members = {n for n, _ in MOD.collect_archive_members(skills[0])}
            self.assertIn("README.md", members)
            MOD.build_catalog(skills, Path(tmp) / "site")

    def test_referenced_omitted_file_fails_check(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "skills"
            skill_dir = root / "points-at-sync"
            write_skill(
                skill_dir,
                "points-at-sync",
                "Do not ship [SYNC.md](SYNC.md).\n",
            )
            (skill_dir / "SYNC.md").write_text("maintainer\n", encoding="utf-8")
            skills = MOD.discover_skills(root)
            members = {n for n, _ in MOD.collect_archive_members(skills[0])}
            self.assertNotIn("SYNC.md", members)
            with self.assertRaises(SystemExit) as ctx:
                MOD.build_catalog(skills, Path(tmp) / "site")
            self.assertIn("SYNC.md", str(ctx.exception))


class FrontmatterTests(unittest.TestCase):
    def test_plain_wrapped_description(self) -> None:
        content = """---
name: wrapped-skill
description: Use when writing, modifying, or reviewing an Agent Skill — covers
  the allowed frontmatter fields, the 500-line guideline, and this repo's eval
  checklist. Load before creating or editing any skill.
---

Body.
"""
        fields = MOD.parse_frontmatter(content)
        self.assertEqual(fields["name"], "wrapped-skill")
        self.assertEqual(
            fields["description"],
            "Use when writing, modifying, or reviewing an Agent Skill — covers "
            "the allowed frontmatter fields, the 500-line guideline, and this "
            "repo's eval checklist. Load before creating or editing any skill.",
        )

    def test_folded_block_description(self) -> None:
        content = """---
name: folded-skill
description: >
  Use when writing, modifying, or reviewing an Agent Skill — covers
  the allowed frontmatter fields, the 500-line guideline, and this repo's eval
  checklist. Load before creating or editing any skill.
---

Body.
"""
        fields = MOD.parse_frontmatter(content)
        self.assertEqual(fields["name"], "folded-skill")
        self.assertEqual(
            fields["description"],
            "Use when writing, modifying, or reviewing an Agent Skill — covers "
            "the allowed frontmatter fields, the 500-line guideline, and this "
            "repo's eval checklist. Load before creating or editing any skill.",
        )
        self.assertNotEqual(fields["description"], ">")

    def test_folded_block_is_published_in_index(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "skills"
            write_skill_frontmatter(
                root / "folded-skill",
                """---
name: folded-skill
description: >
  First line of a folded description
  continues here.
---

Body.
""",
            )
            skills = MOD.discover_skills(root)
            self.assertEqual(len(skills), 1)
            self.assertEqual(
                skills[0].description,
                "First line of a folded description continues here.",
            )

    def test_inline_comment_is_not_part_of_value(self) -> None:
        fields = MOD.parse_frontmatter(
            """---
name: comment-skill
description: Visible text # not published
---

Body.
"""
        )
        self.assertEqual(fields["description"], "Visible text")

    def test_multiline_double_quoted_description(self) -> None:
        fields = MOD.parse_frontmatter(
            """---
name: quoted-skill
description: "First line
  second line"
---

Body.
"""
        )
        self.assertEqual(fields["name"], "quoted-skill")
        self.assertEqual(fields["description"], "First line second line")

    def test_folded_block_with_blank_line_keeps_paragraph_break(self) -> None:
        fields = MOD.parse_frontmatter(
            """---
name: folded-paragraphs
description: >
  First paragraph continues
  on the next line.

  Second paragraph.
---

Body.
"""
        )
        self.assertEqual(
            fields["description"],
            "First paragraph continues on the next line.\nSecond paragraph.",
        )

    def test_literal_block_preserves_newlines(self) -> None:
        fields = MOD.parse_frontmatter(
            """---
name: literal-skill
description: |
  Line one
  Line two
---

Body.
"""
        )
        self.assertEqual(fields["description"], "Line one\nLine two")

    def test_non_string_name_fails(self) -> None:
        with self.assertRaises(ValueError) as ctx:
            MOD.parse_frontmatter(
                """---
name: 12
description: ok
---

Body.
"""
            )
        self.assertIn("name must be a string", str(ctx.exception))

    def test_unquoted_colon_space_is_invalid_yaml(self) -> None:
        with self.assertRaises(ValueError) as ctx:
            MOD.parse_frontmatter(
                """---
name: colon-skill
description: Covers the spec: the allowed fields.
---

Body.
"""
            )
        self.assertIn("invalid YAML frontmatter", str(ctx.exception))


def write_skill_frontmatter(directory: Path, content: str) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "SKILL.md").write_text(content, encoding="utf-8")


if __name__ == "__main__":
    unittest.main()
