#!/usr/bin/env python3
"""Deploy SOLA plugin to dev.sylr.org via AWS SSM + S3 + rsync.

This bypasses Moodle's plugin installer (which doesn't reliably extract all
files) and copies the plugin directly to the server filesystem via AWS Systems
Manager Session Manager.

Requirements:
    - AWS CLI configured with credentials (aws configure)
    - Access to EC2 instance i-04c58928fad484d97 (moodle-test)
    - Access to S3 bucket archive-course

Usage:
    python3 deploy_dev.py
"""

import json
import os
import pathlib
import subprocess
import sys
import time

# AWS infrastructure.
INSTANCE_ID = "i-04c58928fad484d97"
S3_BUCKET = "archive-course"
S3_KEY = "ai_course_assistant_deploy.tar.gz"

# Paths on the remote server.
REMOTE_PLUGIN_DIR = "/var/www/html/moodle/local/ai_course_assistant"
REMOTE_MOODLE_DIR = "/var/www/html/moodle"

# Local paths.
PLUGIN_DIR = pathlib.Path(__file__).resolve().parent
PARENT_DIR = PLUGIN_DIR.parent
LOCAL_TAR = pathlib.Path("/tmp/ai_course_assistant_deploy.tar.gz")


def run(cmd, **kwargs):
    """Run a shell command and return the result."""
    return subprocess.run(cmd, capture_output=True, text=True, **kwargs)


def ssm_send(commands, wait=True, timeout=120):
    """Send a shell command to the EC2 instance via SSM and wait for result."""
    payload = {"commands": commands}
    result = run([
        "aws", "ssm", "send-command",
        "--instance-ids", INSTANCE_ID,
        "--document-name", "AWS-RunShellScript",
        "--parameters", json.dumps(payload),
        "--query", "Command.CommandId",
        "--output", "text",
    ])
    if result.returncode != 0:
        print(f"  ERROR: {result.stderr}")
        return None
    command_id = result.stdout.strip()

    if not wait:
        return command_id

    # Poll for completion.
    start = time.time()
    while time.time() - start < timeout:
        time.sleep(2)
        r = run([
            "aws", "ssm", "get-command-invocation",
            "--command-id", command_id,
            "--instance-id", INSTANCE_ID,
            "--query", "[Status,StandardOutputContent,StandardErrorContent]",
            "--output", "json",
        ])
        if r.returncode != 0:
            continue
        try:
            status, stdout, stderr = json.loads(r.stdout)
        except (json.JSONDecodeError, ValueError):
            continue
        if status in ("Success", "Failed", "Cancelled", "TimedOut"):
            return {"status": status, "stdout": stdout or "", "stderr": stderr or ""}
    return {"status": "Timeout", "stdout": "", "stderr": "Command polling timed out"}


def main():
    print("=" * 60)
    print("SOLA dev deploy (AWS SSM + rsync)")
    print("=" * 60)

    # Step 1: Verify AWS CLI is available and configured.
    print("\nStep 1: Verifying AWS credentials...")
    r = run(["aws", "sts", "get-caller-identity", "--query", "Arn", "--output", "text"])
    if r.returncode != 0:
        print("  ERROR: AWS CLI not configured. Run 'aws configure' first.")
        sys.exit(1)
    print(f"  Authenticated as: {r.stdout.strip()}")

    # Step 2: Create tarball excluding bloat.
    print("\nStep 2: Creating plugin tarball...")
    if LOCAL_TAR.exists():
        LOCAL_TAR.unlink()
    r = run([
        "tar", "-czf", str(LOCAL_TAR),
        "-C", str(PARENT_DIR),
        "--exclude=ai_course_assistant/cdn/node_modules",
        "--exclude=ai_course_assistant/cdn/dist",
        "--exclude=ai_course_assistant/.wiki",
        "--exclude=ai_course_assistant/.git",
        "--exclude=.DS_Store",
        "ai_course_assistant",
    ])
    if r.returncode != 0:
        print(f"  ERROR creating tarball: {r.stderr}")
        sys.exit(1)
    size_mb = LOCAL_TAR.stat().st_size / 1024 / 1024
    print(f"  Created: {LOCAL_TAR} ({size_mb:.1f} MB)")

    # Step 3: Upload tarball to S3.
    print("\nStep 3: Uploading to S3...")
    r = run(["aws", "s3", "cp", str(LOCAL_TAR), f"s3://{S3_BUCKET}/{S3_KEY}"])
    if r.returncode != 0:
        print(f"  ERROR uploading to S3: {r.stderr}")
        sys.exit(1)
    print(f"  Uploaded to s3://{S3_BUCKET}/{S3_KEY}")

    # Step 4: Deploy on the server via SSM.
    print("\nStep 4: Deploying on server via SSM...")
    deploy_commands = [
        "set -e",
        "cd /tmp",
        f"aws s3 cp s3://{S3_BUCKET}/{S3_KEY} ./ai_course_assistant_deploy.tar.gz",
        "rm -rf /tmp/sola_extract && mkdir -p /tmp/sola_extract",
        "tar -xzf /tmp/ai_course_assistant_deploy.tar.gz -C /tmp/sola_extract 2>&1 | grep -v 'LIBARCHIVE' || true",
        f"sudo rsync -a --delete /tmp/sola_extract/ai_course_assistant/ {REMOTE_PLUGIN_DIR}/",
        f"sudo chown -R www-data:www-data {REMOTE_PLUGIN_DIR}",
        f"sudo find {REMOTE_PLUGIN_DIR} -type f -exec chmod 644 {{}} \\;",
        f"sudo find {REMOTE_PLUGIN_DIR} -type d -exec chmod 755 {{}} \\;",
        "rm -rf /tmp/sola_extract /tmp/ai_course_assistant_deploy.tar.gz",
        "echo Deploy complete",
    ]
    result = ssm_send(deploy_commands, timeout=120)
    if not result or result["status"] != "Success":
        print(f"  ERROR: {result}")
        sys.exit(1)
    print("  Files synced to server")

    # Step 5: Run Moodle upgrade.
    print("\nStep 5: Running Moodle database upgrade...")
    upgrade_commands = [
        f"sudo -u www-data php {REMOTE_MOODLE_DIR}/admin/cli/upgrade.php --non-interactive 2>&1 | tail -30",
    ]
    result = ssm_send(upgrade_commands, timeout=180)
    if result:
        output = result.get("stdout", "")
        # Print only interesting lines.
        for line in output.split("\n"):
            if line.strip() and not line.startswith("++") and "LIBARCHIVE" not in line:
                print(f"  {line}")

    # Step 6: Purge caches.
    print("\nStep 6: Purging caches...")
    purge_commands = [
        f"sudo -u www-data php {REMOTE_MOODLE_DIR}/admin/cli/purge_caches.php",
    ]
    result = ssm_send(purge_commands, timeout=60)
    print("  Caches purged")

    # Step 7: Verify.
    print("\nStep 7: Verifying deployment...")
    verify_commands = [
        f"cat {REMOTE_PLUGIN_DIR}/version.php | grep -E 'version|release'",
        f"ls {REMOTE_PLUGIN_DIR}/*.php | wc -l",
        f"grep -c cdn_url {REMOTE_PLUGIN_DIR}/settings.php",
    ]
    result = ssm_send([" && ".join(verify_commands)], timeout=30)
    if result and result.get("stdout"):
        for line in result["stdout"].strip().split("\n"):
            print(f"  {line}")

    # Clean up local tarball.
    if LOCAL_TAR.exists():
        LOCAL_TAR.unlink()

    print("\n" + "=" * 60)
    print("Deploy complete: https://dev.sylr.org")
    print("=" * 60)


if __name__ == "__main__":
    main()
