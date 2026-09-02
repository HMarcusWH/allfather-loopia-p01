# Project Boundary

## What this repository is

A deployable Loopia-specific implementation of the minimum governed runtime surface required for Allfather BI P01.

## What this repository is not

It is not:

- the One-Field host;
- GF-AoA runtime law;
- the canonical Allfather control substrate;
- a general Allfather rewrite;
- a port of every Allfather module;
- P02–P07;
- a general-purpose agent host;
- an external-action runtime.

## Allowed scope

The deployment may implement the P01 slices required for:

`source authority -> governed read-only acquisition -> evidence capture -> diff -> CompanySignal -> relevance review -> admissibility -> release record -> customer briefing projection`

The projection may exercise bounded equivalents of the upstream M02/M03/M04/M05/M06/M07/M08/M10/M11/M12 laws only insofar as P01 requires them.

## Hard prohibitions

The deployed runtime must not acquire authority to:

- write to CRM systems;
- send email or SMS;
- post or publish to third-party systems;
- execute trades or financial transactions;
- charge customers externally;
- mutate arbitrary external systems;
- silently convert hypotheses into facts;
- release intelligence without the required evidence and admissibility chain.

Unknown authority is refusal, not permission.
