"""Add tools/structural/ to sys.path so test files can import project modules."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))
