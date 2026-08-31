"""Python worker entrypoint. Collection and email jobs consume Redis."""

import logging

logging.basicConfig(level=logging.INFO)
log = logging.getLogger("worker")


def main() -> None:
    log.info("ULTIMATE ONLINE PLATFORM worker started (idle until Redis jobs exist).")


if __name__ == "__main__":
    main()
