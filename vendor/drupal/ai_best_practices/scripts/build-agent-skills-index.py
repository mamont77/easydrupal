#!/usr/bin/env python3
"""Build a GitLab Pages Agent Skills discovery index (v0.2.0).

Writes only the generated catalog:

  <out>/.well-known/agent-skills/index.json
  <out>/.well-known/agent-skills/<name>.tar.gz

The static landing page is pages/index.html. GitLab Pages publishes the
pages/ directory, so this script does not copy or generate HTML.

Requires PyYAML (``pip install pyyaml``). ``evals/run-evals.py --static``
stays pip-free; only the catalog jobs install it.

Usage:
  python3 scripts/build-agent-skills-index.py
  python3 scripts/build-agent-skills-index.py --out pages
  python3 scripts/build-agent-skills-index.py --check
  python3 scripts/build-agent-skills-index.py --dry-run
"""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
import re
import sys
import tarfile
import tempfile
from dataclasses import dataclass
from pathlib import Path

try:
    import yaml
except ImportError as exc:  # pragma: no cover
    yaml = None
    _YAML_IMPORT_ERROR = exc
else:
    _YAML_IMPORT_ERROR = None

REPO_ROOT = Path(__file__).resolve().parent.parent
# Opaque version tag, not a document to fetch (the host does not resolve, by
# design). Clients string-compare it: an absent $schema means legacy v0.1.0 and
# any other value makes them reject the index. Do not "fix" this URL.
SCHEMA_URI = "https://schemas.agentskills.io/discovery/0.2.0/schema.json"
SKILL_NAME_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
OMIT_NAMES = frozenset({"SYNC.md", ".DS_Store"})
MD_LINK_RE = re.compile(r"\]\(([^)]+)\)")
BACKTICK_RE = re.compile(r"`([^`]+)`")
# Fixed mtime so identical skill trees produce identical archives/digests.
ARCHIVE_MTIME = 0
DIGEST_PREFIX = "sha256:"


@dataclass(frozen=True)
class Skill:
    name: str
    description: str
    directory: Path
    skill_md: Path


def parse_frontmatter(content: str) -> dict[str, str]:
    """Load name and description from YAML frontmatter via PyYAML."""
    if yaml is None:
        raise SystemExit(
            "PyYAML is required to build the skills catalog (pip install pyyaml)"
        ) from _YAML_IMPORT_ERROR

    match = re.search(r"^---\n(.*?)\n---", content, re.DOTALL)
    if not match:
        raise ValueError("missing YAML frontmatter")

    try:
        loaded = yaml.safe_load(match.group(1))
    except yaml.YAMLError as exc:
        raise ValueError(f"invalid YAML frontmatter: {exc}") from exc

    if loaded is None:
        loaded = {}
    if not isinstance(loaded, dict):
        raise ValueError("frontmatter must be a YAML mapping")

    fields: dict[str, str] = {}
    for key in ("name", "description"):
        if key not in loaded or loaded[key] is None:
            fields[key] = ""
            continue
        value = loaded[key]
        if not isinstance(value, str):
            raise ValueError(f"{key} must be a string, got {type(value).__name__}")
        fields[key] = value.strip()
    return fields


def validate_name(name: str, directory_name: str) -> None:
    if not name or len(name) > 64:
        raise ValueError(f"invalid skill name length: {name!r}")
    if not SKILL_NAME_RE.fullmatch(name):
        raise ValueError(f"invalid skill name: {name!r}")
    if name != directory_name:
        raise ValueError(
            f"frontmatter name {name!r} must match directory {directory_name!r}"
        )


def discover_skills(skills_root: Path) -> list[Skill]:
    if not skills_root.is_dir():
        raise SystemExit(f"skills directory not found: {skills_root}")

    found: list[Skill] = []
    skills_root = skills_root.resolve()
    for skill_md in sorted(skills_root.rglob("SKILL.md")):
        if skill_md.is_symlink():
            raise SystemExit(f"{skill_md}: symlink skill paths are not allowed")
        try:
            skill_md.resolve().relative_to(skills_root)
        except ValueError:
            raise SystemExit(f"{skill_md}: skill path resolves outside {skills_root}")
        skill_dir = skill_md.parent
        if skill_dir.is_symlink():
            raise SystemExit(f"{skill_dir}: symlink skill directories are not allowed")
        content = skill_md.read_text(encoding="utf-8")
        try:
            fields = parse_frontmatter(content)
        except ValueError as exc:
            raise SystemExit(f"{skill_md}: {exc}") from exc

        name = fields.get("name", "")
        description = fields.get("description", "")
        try:
            validate_name(name, skill_dir.name)
        except ValueError as exc:
            raise SystemExit(f"{skill_md}: {exc}") from exc
        if not description:
            raise SystemExit(f"{skill_md}: empty description")
        if len(description) > 1024:
            raise SystemExit(
                f"{skill_md}: description is {len(description)} chars (max 1024)"
            )

        found.append(
            Skill(
                name=name,
                description=description,
                directory=skill_dir,
                skill_md=skill_md,
            )
        )

    names = [s.name for s in found]
    dupes = {n for n in names if names.count(n) > 1}
    if dupes:
        raise SystemExit(f"duplicate skill names: {', '.join(sorted(dupes))}")

    return found


def collect_archive_members(skill: Skill) -> list[tuple[str, Path]]:
    """Regular files under the skill directory, including nested skill trees.

    Nested skills stay independent catalog entries (each SKILL.md is discovered
    on its own) and are also bundled into any ancestor archive so a dispatcher
    skill remains self-contained. Maintainer filenames are omitted at every
    depth. Symlinks are rejected; resolved files must stay under the skill root.
    """
    skill_root = skill.directory.resolve()
    members: list[tuple[str, Path]] = []
    for dirpath, dirnames, filenames in os.walk(skill.directory, followlinks=False):
        current = Path(dirpath)
        if current.is_symlink():
            raise SystemExit(f"{current}: symlink not allowed in skill archives")
        for dirname in dirnames:
            child = current / dirname
            if child.is_symlink():
                raise SystemExit(f"{child}: symlink not allowed in skill archives")
        for filename in filenames:
            path = current / filename
            if path.is_symlink():
                raise SystemExit(f"{path}: symlink not allowed in skill archives")
            if filename in OMIT_NAMES or not path.is_file():
                continue
            resolved = path.resolve()
            try:
                resolved.relative_to(skill_root)
            except ValueError:
                raise SystemExit(
                    f"{path}: resolves to {resolved}, which is outside {skill_root}"
                )
            rel = path.relative_to(skill.directory).as_posix()
            if rel.startswith("/") or ".." in Path(rel).parts:
                raise SystemExit(f"{path}: unsafe archive member name {rel!r}")
            members.append((rel, path))
    members.sort(key=lambda item: item[0])
    if not any(arcname == "SKILL.md" for arcname, _ in members):
        raise SystemExit(f"{skill.directory}: archive missing SKILL.md")
    return members


def local_file_refs(skill: Skill) -> list[str]:
    """Relative local files the root SKILL.md points at that exist on disk."""
    text = skill.skill_md.read_text(encoding="utf-8")
    body = re.sub(r"^---\n.*?\n---\n?", "", text, count=1, flags=re.DOTALL)
    skill_root = skill.directory.resolve()
    refs: list[str] = []
    seen: set[str] = set()
    for raw in MD_LINK_RE.findall(body) + BACKTICK_RE.findall(body):
        token = raw.strip()
        if not token:
            continue
        token = token.split()[0].split("#", 1)[0]
        if not token or token.startswith(("http://", "https://", "mailto:", "/")):
            continue
        candidate = (skill.directory / token).resolve()
        try:
            rel = candidate.relative_to(skill_root).as_posix()
        except ValueError:
            continue
        if ".." in Path(rel).parts or not candidate.is_file() or candidate.is_symlink():
            continue
        if rel not in seen:
            seen.add(rel)
            refs.append(rel)
    return refs


def archive_member_names(archive_path: Path) -> set[str]:
    names: set[str] = set()
    with tarfile.open(archive_path, mode="r:gz") as tar:
        for info in tar.getmembers():
            if info.issym() or info.islnk():
                raise SystemExit(
                    f"{archive_path}: archive contains a link entry {info.name!r}"
                )
            if not info.isfile():
                raise SystemExit(
                    f"{archive_path}: archive contains a non-file entry {info.name!r}"
                )
            if info.name.startswith("/") or ".." in Path(info.name).parts:
                raise SystemExit(
                    f"{archive_path}: unsafe archive member {info.name!r}"
                )
            names.add(info.name)
    return names


def archive_bytes(members: list[tuple[str, Path]]) -> bytes:
    tar_buf = io.BytesIO()
    with tarfile.open(fileobj=tar_buf, mode="w", format=tarfile.PAX_FORMAT) as tar:
        for arcname, path in members:
            data = path.read_bytes()
            info = tarfile.TarInfo(name=arcname)
            info.size = len(data)
            info.mtime = ARCHIVE_MTIME
            info.uid = 0
            info.gid = 0
            info.uname = ""
            info.gname = ""
            info.mode = 0o644
            tar.addfile(info, io.BytesIO(data))
    return gzip.compress(tar_buf.getvalue(), compresslevel=9, mtime=ARCHIVE_MTIME)


def sha256_digest(data: bytes) -> str:
    return DIGEST_PREFIX + hashlib.sha256(data).hexdigest()


def build_index_document(skills: list[Skill]) -> tuple[dict, list[tuple[str, bytes]]]:
    archives: list[tuple[str, bytes]] = []
    entries = []
    for skill in sorted(skills, key=lambda s: s.name):
        archive_name = f"{skill.name}.tar.gz"
        blob = archive_bytes(collect_archive_members(skill))
        archives.append((archive_name, blob))
        entries.append(
            {
                "name": skill.name,
                "type": "archive",
                "description": skill.description,
                "url": archive_name,
                "digest": sha256_digest(blob),
            }
        )
    return {"$schema": SCHEMA_URI, "skills": entries}, archives


def write_index(skills: list[Skill], well_known: Path) -> dict:
    index, archives = build_index_document(skills)
    well_known.mkdir(parents=True, exist_ok=True)
    for archive_name, blob in archives:
        dest = well_known / archive_name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(blob)
    (well_known / "index.json").write_text(
        json.dumps(index, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    return index


def build_catalog(skills: list[Skill], out_dir: Path) -> dict:
    well_known = out_dir / ".well-known" / "agent-skills"
    if well_known.exists():
        for child in well_known.iterdir():
            if child.is_file():
                child.unlink()
    well_known.mkdir(parents=True, exist_ok=True)
    index = write_index(skills, well_known)
    validate_built_catalog(skills, out_dir)
    return index


def validate_built_catalog(skills: list[Skill], out_dir: Path) -> None:
    index_path = out_dir / ".well-known" / "agent-skills" / "index.json"
    if not index_path.is_file():
        raise SystemExit(f"missing {index_path}")

    index = json.loads(index_path.read_text(encoding="utf-8"))
    if index.get("$schema") != SCHEMA_URI:
        raise SystemExit(f"unexpected $schema: {index.get('$schema')!r}")
    entries = index.get("skills")
    if not isinstance(entries, list) or not entries:
        raise SystemExit("index.json skills[] is empty or missing")

    by_name = {s.name: s for s in skills}
    seen: set[str] = set()
    digest_re = re.compile(r"^sha256:[a-f0-9]{64}$")

    for entry in entries:
        name = entry.get("name")
        if name not in by_name:
            raise SystemExit(f"index lists unknown skill {name!r}")
        if name in seen:
            raise SystemExit(f"duplicate index entry {name!r}")
        seen.add(name)
        skill = by_name[name]
        if entry.get("type") != "archive":
            raise SystemExit(f"{name}: type must be archive")
        if entry.get("description") != skill.description:
            raise SystemExit(f"{name}: description does not match SKILL.md frontmatter")
        url = entry.get("url")
        if not isinstance(url, str) or url.startswith("/") or "://" in url or "/" in url:
            raise SystemExit(f"{name}: url must be a relative filename, got {url!r}")
        digest = entry.get("digest")
        if not isinstance(digest, str) or not digest_re.fullmatch(digest):
            raise SystemExit(f"{name}: invalid digest {digest!r}")
        archive_path = index_path.parent / url
        if not archive_path.is_file():
            raise SystemExit(f"{name}: missing archive {archive_path}")
        actual = sha256_digest(archive_path.read_bytes())
        if actual != digest:
            raise SystemExit(f"{name}: digest mismatch (index {digest}, file {actual})")
        member_names = archive_member_names(archive_path)
        for ref in local_file_refs(skill):
            if ref not in member_names:
                raise SystemExit(
                    f"{name}: SKILL.md references {ref} but it is missing from the archive"
                )

    missing = sorted(set(by_name) - seen)
    if missing:
        raise SystemExit(f"index missing skills: {', '.join(missing)}")


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--out",
        default=str(REPO_ROOT / "pages"),
        help="site directory to write .well-known/ into (default: <repo>/pages)",
    )
    parser.add_argument(
        "--skills-dir",
        default=str(REPO_ROOT / "skills"),
        help="skills root (default: <repo>/skills)",
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="build in a temp dir and validate; do not write --out",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="print index.json to stdout; do not write --out",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    skills = discover_skills(Path(args.skills_dir))
    if not skills:
        raise SystemExit("no SKILL.md files found")

    if args.dry_run:
        index, _archives = build_index_document(skills)
        sys.stdout.write(json.dumps(index, indent=2, ensure_ascii=False) + "\n")
        return 0

    if args.check:
        with tempfile.TemporaryDirectory() as tmp:
            build_catalog(skills, Path(tmp))
        print(f"ok: {len(skills)} skills")
        return 0

    out = Path(args.out)
    index = build_catalog(skills, out)
    print(f"wrote {len(index['skills'])} skills to {out / '.well-known' / 'agent-skills'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
