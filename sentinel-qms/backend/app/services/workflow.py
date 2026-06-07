"""Generic state-machine helper for approval / lifecycle transitions."""

from __future__ import annotations

from collections.abc import Iterable
from enum import Enum

from app.core.exceptions import WorkflowError


class StateMachine:
    """Validates transitions for an Enum-backed status field.

    ``transitions`` maps a current state to the set of permitted next states.
    """

    def __init__(self, transitions: dict[Enum, set[Enum]], *, name: str = "record"):
        self.transitions = transitions
        self.name = name

    def can_transition(self, current: Enum, target: Enum) -> bool:
        return target in self.transitions.get(current, set())

    def assert_transition(self, current: Enum, target: Enum) -> None:
        if current == target:
            return
        if not self.can_transition(current, target):
            allowed = ", ".join(sorted(s.value for s in self.transitions.get(current, set())))
            raise WorkflowError(
                f"Cannot move {self.name} from '{current.value}' to '{target.value}'. "
                f"Allowed next states: {allowed or 'none'}."
            )

    def allowed_next(self, current: Enum) -> set[Enum]:
        return set(self.transitions.get(current, set()))


def terminal_states(transitions: dict[Enum, set[Enum]]) -> set[Enum]:
    return {state for state, nxt in transitions.items() if not nxt}


def require_states(current: Enum, allowed: Iterable[Enum], *, action: str) -> None:
    if current not in set(allowed):
        names = ", ".join(s.value for s in allowed)
        raise WorkflowError(
            f"Action '{action}' requires the record to be in one of: {names}; "
            f"it is currently '{current.value}'."
        )
