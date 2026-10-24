"""II Core B1: deterministic synthetic decision slice, no production authority.

Run with: .venv\\Scripts\\python.exe -B II_CORE_B1_SYNTHETIC_DECISION_SLICE_v1.py
Only Python standard library; does not import canonical II code or touch project files.
"""
from __future__ import annotations

from dataclasses import dataclass
from hashlib import sha256
import json
from typing import Literal

EvidenceState = Literal['VERIFIED_SYNTHETIC', 'MISSING', 'CONFLICTED']

@dataclass(frozen=True)
class Evidence:
    evidence_id: str
    claim_id: str
    state: EvidenceState
    observed_value: float | None
    synthetic: bool = True

@dataclass(frozen=True)
class Thesis:
    thesis_id: str
    claim_id: str
    minimum_value: float
    falsification_below: float


def evaluate(evidence: tuple[Evidence, ...], thesis: Thesis) -> dict:
    """Return research-only state. No market price, valuation, or trading action."""
    blockers: list[str] = []
    supporting: list[str] = []
    relevant = [e for e in evidence if e.claim_id == thesis.claim_id]
    if not relevant:
        blockers.append('REQUIRED_EVIDENCE_MISSING')
    if any(not e.synthetic for e in evidence):
        blockers.append('NON_SYNTHETIC_INPUT_NOT_ALLOWED_IN_B1')
    if any(e.state == 'CONFLICTED' for e in relevant):
        blockers.append('CONFLICTED_EVIDENCE')
    if any(e.state == 'MISSING' or e.observed_value is None for e in relevant):
        blockers.append('INCOMPLETE_EVIDENCE')
    observed = [e for e in relevant if e.state == 'VERIFIED_SYNTHETIC' and e.observed_value is not None]
    if len(observed) != 1 or len(relevant) != 1:
        blockers.append('UNAMBIGUOUS_SINGLE_EVIDENCE_REQUIRED')
    if thesis.falsification_below > thesis.minimum_value:
        blockers.append('INVALID_THESIS_THRESHOLD_ORDER')

    falsified = False
    if not blockers:
        item = observed[0]
        supporting.append(item.evidence_id)
        falsified = item.observed_value < thesis.falsification_below
        if falsified:
            blockers.append('THESIS_FALSIFIED_BY_OBSERVATION')
        elif item.observed_value < thesis.minimum_value:
            blockers.append('THESIS_SUPPORT_THRESHOLD_NOT_MET')

    state = 'HOLD' if blockers else 'RESEARCH_REVIEW_READY'
    return {
        'schema': 'II_CORE_B1_SYNTHETIC_V1',
        'thesis_id': thesis.thesis_id,
        'research_state': state,
        'thesis_falsified': falsified,
        'evidence_ids_used': sorted(supporting),
        'blocking_reasons': sorted(set(blockers)),
        'decision_authority': 'NONE_SYNTHETIC_ONLY',
        'production_admission_authorized': False,
        'valuation_authorized': False,
        'trading_authorized': False,
        'outcome_learning_validated': False,
    }


def fingerprint(value: dict) -> str:
    return sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def run() -> None:
    thesis = Thesis('SYNTHETIC_THESIS_001', 'SYNTHETIC_MARGIN', 15.0, 10.0)
    cases = {
        'support': ((Evidence('E_SYN_01', 'SYNTHETIC_MARGIN', 'VERIFIED_SYNTHETIC', 18.0),), 'RESEARCH_REVIEW_READY', False, []),
        'weak': ((Evidence('E_SYN_02', 'SYNTHETIC_MARGIN', 'VERIFIED_SYNTHETIC', 12.0),), 'HOLD', False, ['THESIS_SUPPORT_THRESHOLD_NOT_MET']),
        'falsified': ((Evidence('E_SYN_03', 'SYNTHETIC_MARGIN', 'VERIFIED_SYNTHETIC', 8.0),), 'HOLD', True, ['THESIS_FALSIFIED_BY_OBSERVATION']),
        'missing': (tuple(), 'HOLD', False, ['REQUIRED_EVIDENCE_MISSING']),
        'conflicted': ((Evidence('E_SYN_04', 'SYNTHETIC_MARGIN', 'CONFLICTED', 18.0),), 'HOLD', False, ['CONFLICTED_EVIDENCE']),
        'non_synthetic': ((Evidence('E_REAL_DISALLOWED', 'SYNTHETIC_MARGIN', 'VERIFIED_SYNTHETIC', 18.0, False),), 'HOLD', False, ['NON_SYNTHETIC_INPUT_NOT_ALLOWED_IN_B1']),
        'duplicate': ((Evidence('E_SYN_01', 'SYNTHETIC_MARGIN', 'VERIFIED_SYNTHETIC', 18.0), Evidence('E_SYN_05', 'SYNTHETIC_MARGIN', 'VERIFIED_SYNTHETIC', 20.0)), 'HOLD', False, ['UNAMBIGUOUS_SINGLE_EVIDENCE_REQUIRED']),
    }
    print('=== II CORE B1 EXECUTABLE SYNTHETIC DECISION VERTICAL SLICE ===')
    print('MODE=IN_MEMORY_SYNTHETIC_ONLY_NO_ENGINE_IMPORT_NO_DB_ACCESS_NO_WRITES')
    failures = []
    for name, (items, expected_state, expected_falsified, expected_blockers) in cases.items():
        result = evaluate(items, thesis)
        deterministic = fingerprint(result) == fingerprint(evaluate(items, thesis))
        passed = (
            result['research_state'] == expected_state
            and result['thesis_falsified'] == expected_falsified
            and all(b in result['blocking_reasons'] for b in expected_blockers)
            and not result['trading_authorized']
            and not result['valuation_authorized']
            and not result['production_admission_authorized']
            and deterministic
        )
        if not passed:
            failures.append(name)
        print(f'CASE={name};RESULT={"PASS" if passed else "FAIL"};STATE={result["research_state"]};FALSIFIED={str(result["thesis_falsified"]).upper()};BLOCKERS={",".join(result["blocking_reasons"]) or "NONE"};DIGEST={fingerprint(result)[:16]}')
    print(f'CASE_COUNT={len(cases)};FAILURE_COUNT={len(failures)}')
    print('CANONICAL_PROJECT_CODE_EXECUTED=FALSE;ACTIVE_DB_ACCESSED=FALSE;ARTIFACT_WRITES=FALSE')
    print('ACTUAL_II_ENGINE_INTEGRATION_VERIFIED=FALSE;USER_DECISION_IMPROVEMENT_VERIFIED=FALSE')
    print('PRODUCT_STATE=R0_INTERNAL_RESEARCH;REAL_MONEY_TRADING_AUTHORIZED=FALSE')
    print('CORE_B1_RESULT=' + ('PASS_SYNTHETIC_DECISION_SLICE_FUNCTIONAL' if not failures else 'HOLD_SYNTHETIC_DECISION_SLICE_FAILURE'))
    if failures:
        raise SystemExit(1)


if __name__ == '__main__':
    run()
