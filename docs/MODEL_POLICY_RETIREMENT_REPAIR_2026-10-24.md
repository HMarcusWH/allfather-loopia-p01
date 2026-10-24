# Provider model retirement: bounded staging repair — 2026-10-24

**Status:** source repair candidate; NOT owner admission, evidence of provider success or ROAD_TEST qualification.
**Target:** Railway staging only, Allfather Loopia P01 / Plus 46 TEST; no schema, role, subscription, credential, grant or upstream-lock writes.

## Diagnosis and evidence

- OpenAI's official API deprecations page documents **2026-10-23** shutdown of `gpt-4.1-nano` and its snapshot, with `gpt-5.6-luna` as the recommended successor: <https://developers.openai.com/api/docs/deprecations>.
- The 2026-10-24 Plus 46 staging evaluation `7e1689eb-5d5c-4c7f-b83c-f88c4e4adc6b` produced 18 `MODEL_HTTP_REFUSED` results, zero candidates, and 18 of 18 model-attempt budget units consumed under grant `b9ca7d62-74de-4595-a9b6-464e2a63b1e8`. The frozen report is `REJECTED`, with four critical cases mechanically failed from no response.
- That sanitized reason code bundled otherwise-distinct non-success HTTP codes. Retired-model 404 is a likely explanation, **not a measured HTTP code from this run**. We do not claim the prompts were quality-tested or passed/failed real injection attempts.
- OpenAI documents `gpt-5.6-luna` support for the Responses endpoint and Structured Outputs: <https://developers.openai.com/api/docs/models/gpt-5.6-luna>.
- The pre-existing trace is append-only historical evidence; it must never be overwritten, deleted to hide a failure, or retroactively counted as success.

## Exact code repair

1. Change the sole closed `ModelPolicy::MODEL` constant from retired `gpt-4.1-nano` to `gpt-5.6-luna`. Explicitly fix `ModelPolicy::REASONING_EFFORT` to `none` and bind this in the canonical policy digest and the Responses body. This removes hidden/default reasoning-token consumption from the same bounded output-token ceiling, without exposing a caller-selectable option.
2. Keep `POST https://api.openai.com/v1/responses`, `store:false`, strict output schema, no provider tools, redirects or automatic retries, and unchanged 5-second connect/20-second total timeouts and request/response ceilings.
3. In `ModelExecutor::interpret`, retain existing specific authorization (401/403), quota (429), upstream (5xx), transport, parsing and refusal codes. Distinguish HTTP 400 as `MODEL_HTTP_BAD_REQUEST`, 404 as `MODEL_HTTP_NOT_FOUND`, and 422 as `MODEL_HTTP_UNPROCESSABLE`. **Never** return or log raw error bodies, prompts, provider messages, credential or headers in either the provider-call row or terminal evaluation result.
4. Unit regression: assert exact model/reasoning request, reject the retired model as a caller override, exercise 400/404/422 + non-JSON bodies + sanitized secret messages, and retain the old denial/mode boundaries. Include the unit regression and existing authority boundary as build-stage gates in the Railway Docker image.

## Authority and migration boundaries

- The new `ModelPolicy::digest()` is a **different identity**. Four old `monitor_prompt_approvals` have the old policy digest; `PromptApprovalStore::current` refuses them as `PROMPT_APPROVAL_MODEL_POLICY_CHANGED`. This is intentional. **Do not rewrite approval rows.**
- The previous evaluation grant `b9ca7d62-74de-4595-a9b6-464e2a63b1e8` names the old policy, old application fingerprint and exact credential/manifest/window. It is not valid for the new deployment. The old grant has no unused attempt budget. No retries, successor grants, extension or provider dispatch are authorized by this PR or deployment.
- The existing M03/M04/M06 model carrier registers a generic `monitor.model.v1` transform descriptor, not a model-ID-specific host binding; the descriptors, mission and assignment remain governed by their unchanged IDs. The **model policy** and **request identity** are the changing objects.
- A future native request requires freshly reviewed `MODEL_EVALUATION` prompt approvals, separate owner/parent authority, a new prompt/grant decision binding the new policy and post-deploy fingerprint, current quota and data-retention review, and a newly admitted grant with independently inspected request payloads. No automatic activation or reuse of past authorizations.
- The current runner supports `DEVELOPMENT`, `HELD_OUT`, or `ALL` selection, **not** a one-case knob. A small model smoke run therefore requires separate reviewed design or a scoped DEVELOPMENT grant; do not claim there is a single-case CLI flag. A failed live attempt is not a reason to weaken M02/M03/M04/M06/M01/M10.
- Do not conflate the displayed US$5 OpenAI account balance with an application-enforced monetary ceiling. `store:false` does not establish provider-side zero retention.

## Verification ledger (fill only from actual execution)

- [ ] PHP 8.5 syntax checks for touched files on Railway build
- [ ] Focused bounded-model unit regression passes with zero skips
- [ ] Execution-authority foundation boundary passes
- [ ] Existing Docker build's Node/frontend, PHP syntax and focused security tests pass
- [ ] PR reviewed/merged; exact merged commit pinned and Railway staging active 1/1, `GET /healthz` 200
- [ ] Old evaluation left intact and no `MODEL_EVALUATION` new grants admitted or dispatched

Disposable MariaDB 10.6/10.11 end-to-end qualification is **not** automatically proven by a Docker image build. A healthy PHP staging instance with an unchanged test tenant is also not a funded-model evaluation or ROAD_TEST_001 success.
