# Stale-response race audit (frontend/src)

Read-only audit. Reference pattern: module-level `let xSeq = 0;`, `const seq = ++xSeq;` at the top of the fetch, `if (seq !== xSeq) return;` before applying, and `if (seq === xSeq)` guards on `loading`/error/catch handling (ScheduleView `fetchSchedule`, SettlementPeriodsView `fetchReport`, MyShiftsView `fetchMyShifts`).

Totals: 47 files inspected. HIGH 14, MEDIUM 10, LOW 6. Line numbers are from the tree at audit time.

## 1. Coverage table

| File | Verdict |
|---|---|
| views/ScheduleView.vue | fetchSchedule guarded (ok). H1 delegations/newbies, H2 availability, M1 closures. fetchShiftColors/fetchTemplates/current-shift: LOW |
| views/SettlementPeriodsView.vue | guarded (reference) |
| views/MyShiftsView.vue | guarded (reference) |
| views/OnCallView.vue | H3 |
| views/OfficeAvailabilityView.vue | H4 |
| views/MyAvailabilityView.vue | H5 (delegations); M9 (entries) |
| views/AlarmsView.vue | H6 |
| views/ChecklistHistoryView.vue | H7 fetchData; M2 viewDetail; M3 openProcedureModal |
| views/ProceduresListView.vue | H8 |
| views/ProcedureDetailView.vue | H9 version switching; M10 openDiffModal |
| views/ChecklistFillView.vue | H10 shifts-by-date; H11 openConfirmation; M3 openProcedureModal; M8 shiftInfo |
| views/DelegationArchiveView.vue | H12 (fetchData on filter change); per-id fetchLogs LOW |
| views/ChecklistBuilderView.vue | H13 editTemplate |
| views/ProceduresToLearnView.vue | M4 |
| views/UsersView.vue | M5 openNewbieModal; fetchGroups/fetchDelegations LOW |
| views/ProcedureTreeEditorView.vue | M7 |
| views/DelegationsManageView.vue | LOW (mount fetch; per-id logs) |
| views/DelegationsView.vue | LOW (single mount fetch) |
| views/DashboardView.vue | LOW (sequential mount fetches, no trigger) |
| views/ProcedureTagsView.vue | LOW (mount fetch; refetch only after own mutations) |
| views/ShiftSettingsView.vue | LOW (mount fetch + post-mutation refetch, no params) |
| views/LoginView.vue | no loads |
| components/procedure/ProcedureAccessLogTab.vue | H14 |
| components/procedure/ProcedureEditorModal.vue | M6 |
| components/procedure/ProcedureContent.vue | no fetch (watch only flips accessLogLoaded) |
| components/procedure/ProcedureDiffModal.vue | no fetch |
| components/procedure/ProcedureFaqTab.vue | mutations only |
| components/procedure/ProcedureFeedbackTab.vue | mutation only |
| components/procedure/ProcedureOwnersTab.vue | mutation only |
| components/procedure/ProcedureTemplatesTab.vue | no fetch |
| components/procedure/ProcedureTreeNode.vue | no fetch |
| components/procedure/TreeEditNode.vue | no fetch |
| components/PlayerSelectionPanel.vue | LOW (monthKey watch, but only mounted inside a modal that blocks month navigation); defensive guard advised |
| components/AutoFillPanel.vue | POSTs only; emits `filled`, which re-runs the guarded fetchSchedule |
| components/ShiftTypeBulkEditor.vue | PUT loop only; watch is a local recompute |
| components/ShiftCountInputs.vue | no fetch |
| components/AppLayout.vue | LOW (60 s alarm poll, no params) |
| components/AppToast.vue, ConfirmModal.vue | no fetch |
| App.vue | no fetch |
| composables/useConfirm.js, useFocusTrap.js, useToast.js | no fetch |
| router/index.js | `beforeEach` awaits `/api/user`; vue-router cancels superseded navigations: not a risk |
| axios.js, app.js, bootstrap.js | no loads |

## 2. HIGH findings

Shared test recipe (use for each): `page.route(matcher, async r => { await held; await r.continue(); })` on the FIRST request, trigger the second request through the UI, wait for its result to render, `release()`, `waitForResponse` on the held one, `waitForLoadState('networkidle')`, then assert the UI still shows the second request's data. Use reserved far-future months (2099-xx) and unique group names like the reference specs.

### H1 ScheduleView: delegations / newbies overlays race on month change
- Trigger: `watch(monthKey)` at ScheduleView.vue:2727 -> guarded `fetchSchedule()`, whose success path calls unawaited `fetchDelegations()` / `fetchNewbies()` (:2070-2071). Also `watch(showDelegations)` :1574, `watch(showNewbies)` :1604.
- Fetch: `/api/delegations/month` :1553, `/api/newbie-periods/month` :1584, both keyed on `monthKey.value` at call time.
- Unguarded assignment: `monthDelegations.value = res.data` :1554 (and `[]` in catch :1556); `monthNewbies.value` :1585/:1587.
- Scenario: A's schedule resolves, so A's delegations request starts (slow). User clicks Next; B's schedule resolves and B's delegations request starts and resolves. A's delegations arrive last, so the overlay and the delegation-conflict check at :2586 (`proceedWithDelegationCheck`, reads `monthDelegations`) run on the wrong month; a real conflict warning is silently skipped. Newbie stars (`isCurrentNewbie`, `getDayNewbies`) likewise.
- Fix: one counter per fetch, as in the reference: `let delegationsSeq = 0; async function fetchDelegations(){ const seq = ++delegationsSeq; try { const res = await api.get(...); if (seq !== delegationsSeq) return; monthDelegations.value = res.data; } catch { if (seq === delegationsSeq) monthDelegations.value = []; } }` and the same with `newbiesSeq`. Separate counters per endpoint; they are independent and do not share `scheduleRequestSeq`.
- Test (schedule spec): delegation in 2099-11 for an employee and none in 2099-12; hold `/api/delegations/month?month=2099-11`; open the schedule at 2099-11 (schedule request resolves, delegations held), click Next to 2099-12, enable the Delegations overlay, wait for December to render, release, assert no November delegation chip. Variant: delegation in December, hold November's, assert December's chip is present.

### H2 ScheduleView: availability highlight race (employee / month)
- Trigger: `toggleEmployeeSelect` -> :2797, `onDragStart` -> :2348, month `watch` :2729, onMounted highlight :2765.
- Fetch: `fetchAvailability(userId)` :2806; assignment `selectedAvailability.value = res.data` :2811 (catch :2813 blanks it).
- Scenario: click employee A, then employee B (or step month); A's slower response lands last, so B is selected but A's unavailable/preferred days are painted. A response arriving after `deselectEmployee()` (:2802) also repopulates `selectedAvailability`.
- Fix: `let availabilitySeq = 0;` in `fetchAvailability` take `seq = ++availabilitySeq`; guard the apply and the catch; in `deselectEmployee()` do `availabilitySeq++` so in-flight responses are dropped.
- Test: two employees with different unavailability in 2099-11; hold the request for employee A (`/api/user-availabilities/<A>/month`), click A then B, assert B's day style, release, assert still B's (A's cells not highlighted). Add a deselect variant.

### H3 OnCallView: month stepping and group select
- Trigger: `stepMonth()` :172-181 calls `fetchAssignments()` per click; `<select @change="fetchAssignments">` :10.
- Fetch: :217-232, params `shift_group_id` + `month`. Unguarded `assignments.value = res.data` :226, catch blanks :228, `finally loading=false` :230 (clears loading while a newer request is pending).
- Scenario: click Next twice quickly or change group then month; an older response renders another month's or group's on-call periods under the current label. At mount, `fetchAssignments` after `fetchGroups` (:323) can also race an early step.
- Fix: `let assignmentsSeq = 0;` guard apply/catch/finally exactly like `fetchReport` (the early-return `!selectedGroupId` branch must also take a seq so it supersedes older requests).
- Test (on-call/on-call.spec.js): hold the month=2099-10 request, click Next to 2099-11, wait for November's rows, release, assert November heading and rows; a second test for group switch holding the first group's request.

### H4 OfficeAvailabilityView: closures month stepping
- Trigger: `watch(monthKey, fetchClosures)` :330; also awaited after save/delete (:285/:304).
- Fetch: :258-265; unguarded `closures.value = res.data` :261, catch blanks :263.
- Scenario: Next, Next quickly; an older month's closures are displayed under the new month label; a save in progress calls fetchClosures too, so it races the watcher.
- Fix: `let closuresSeq = 0;` guard apply and catch.
- Test: closure in 2099-11 only; hold the 2099-11 request, navigate to 11 then 12, release the 11 request after 12 rendered, assert no November closure under December.

### H5 MyAvailabilityView: delegations overlay
- Trigger: `watch(monthKey)` :323 and onMounted :301.
- Fetch: `fetchDelegations` :312; unguarded `delegations.value = res.data` :315, catch blanks :317. (Its sibling `fetchEntries` is M9.)
- Scenario and fix: identical to MyShiftsView's already-fixed delegations part; add `delegationsSeq`.
- Test: copy the my-shifts "slow load" test, holding `/api/my-delegations?month=<prev>` and asserting the current month's delegation is displayed.

### H6 AlarmsView: pagination and filters
- Trigger: `watch([filterType, filterStatus, filterDateFrom, filterDateTo])` :247, `watch(page)` :251, prev/next buttons :108/:113, onMounted :252.
- Fetch: `fetchAlarms` :146; unguarded `alarms.value`/`totalPages.value` :155-156, catch blanks :158, finally `loading=false` :160. A filter change while on page>1 fires two fetches (filter watcher, then `page` watcher).
- Scenario: click Next page twice, or change a filter then page; the older page/filter result lands last, so the list does not match the filter or pager (`totalPages` too). The loading state clears while a newer page is pending.
- Fix: `let alarmsSeq = 0;` apply / catch / finally guards. Optionally skip the duplicate fetch.
- Test: seed more than one page of alarms of two types; hold the page-1/unfiltered request, change the Type filter, wait for the filtered list, release, assert only filtered types are visible.

### H7 ChecklistHistoryView: Apply / Reset filters
- Trigger: `applyFilters` :623 and `resetFilters` :628 (buttons), onMounted :748.
- Fetch: `fetchData` :598; unguarded `submissions.value` etc. :610-612, catch blanks :614, finally :616.
- Scenario: change a filter, Apply, change it again, Apply (or Apply then Reset); the older result wins and the table no longer matches the filter bar. The unfiltered mount fetch can also land after an early Apply.
- Fix: `let submissionsSeq = 0;` guard apply/catch/finally (templates/employees are assigned in the same block, so one guard covers all).
- Test: hold the first `/api/checklist-submissions` request (the unfiltered one), apply a status filter, assert filtered rows, release, assert rows unchanged.

### H8 ProceduresListView: search / filters / sort / paging (also changes selection state)
- Trigger: debounced `watch(searchQuery)` :541, multi-source filter watcher :549 (deep), `goToPage` :412, onMounted `fetchInitial` :567.
- Fetch: `fetchProcedures` :488; unguarded `procedures.value`, `totalProcedures`, `lastPage` :510-513 and **`currentPage.value = res.data.current_page` :512 (selection state snap)**; `finally { loading=false; filtering=false }` :516 clears the indicator while a newer fetch is running.
- Scenario: type a search, the 300 ms debounce fires, then toggle a tag or sort before the response; or click page 2 then page 3. The older response lands last, so results and pager show the old query and the pager snaps back.
- Fix: `let proceduresSeq = 0;` guard the apply block, catch and finally (`filtering` only clears for the latest). `fetchInitial` should not clear `loading` for a superseded call either.
- Test: hold the default request (page=1), click a sort header (or select a tag) so a second request is sent, wait for it to render, release, assert list, pager (`current_page`) and sort indicator still match the second.

### H9 ProcedureDetailView: version browsing
- Trigger: `watch(() => route.params.version)` :371 (prev/next version via `goToVersion` :290 pushes routes; the two route records share the component so the instance is reused).
- Fetch: `loadVersionData(v)` :298 -> `versionData.value = res.data` :305 (unconditional; catch :307 sets null).
- Scenario: click "older version" twice quickly: the v3 fetch resolves after the v2 fetch; `viewingVersion` says 2 but the content, title and diff show v3. Uncached versions only.
- Fix: `let versionSeq = 0;` in `loadVersionData` (guard apply and catch). Also drop in `fetchProcedure` when it loads a version.
- Test: procedure with 3 versions; navigate to version 2 then version 1 through the UI; hold the version-2 request; release after version 1 rendered; assert content equals version 1's text.

### H10 ChecklistFillView: shifts for date
- Trigger: `<input type=date @change="fetchShiftsForDate">` :23, plus the mount path via `loadData` :635.
- Fetch: :646-658; it clears `availableShifts`/`setup.shiftId` synchronously, then unguarded `availableShifts.value = res.data` :652 and the `preserveShiftId` restore :655 (selection state).
- Scenario: change the date twice quickly; the first date's shifts are offered under the second date, and the user can start a checklist for the wrong shift.
- Fix: `let shiftsSeq = 0;` guard after the await.
- Test: hold `/api/shifts-by-date?date=<d1>`; fill date d2 and wait for d2's shift option; release; assert the shift select still lists only d2's shifts.

### H11 ChecklistFillView: opening a pending confirmation
- Trigger: row click `@click="openConfirmation(sub)"` :315. The list stays on screen until the response, so a second click is possible.
- Fetch: :849-860; unguarded `confirmSubmission.value = response.data` :852, then `step='confirm-detail'`.
- Scenario: click submission A then B before A resolves; the last response wins; the user sees A while having clicked B and can confirm the wrong checklist (`confirmSubmission.id` is what the PUT at :914 uses).
- Fix: `let confirmationSeq = 0;` guard after the await.
- Test: two pending submissions; hold `/api/checklist-submissions/<A>`; click A, click B, wait for B's detail, release, assert the detail still shows B.

### H12 DelegationArchiveView: filter changes
- Trigger: five `@change="fetchData"` handlers :21-47, onMounted :309, unarchive :267.
- Fetch: :285-305; unguarded `archived.value` (and destinations/users) :298-300, finally :304.
- Scenario: change destination then employee filter fast; the older filter set's rows are shown under the new filters.
- Fix: `let archiveSeq = 0;` guard apply/finally; ideally split the static destinations/users load out of the filter path.
- Test: hold the first filtered request (`destination=X`), choose another destination, assert rows, release, assert unchanged.

### H13 ChecklistBuilderView: opening a template for edit (can save wrong data)
- Trigger: edit button -> `editTemplate(tpl)` :361. `editingTemplate.value = tpl` is set synchronously (:362) while the form is filled only when the response arrives (:365-382).
- Scenario: click Edit on A then B; B's response first, A's last: `editingTemplate` is B but the form holds A's content; Save PUTs A's content over B. This is a data-loss path.
- Fix: `let editSeq = 0;` guard after the await (and the catch toast).
- Test: two templates; hold `GET /api/checklist-templates/<A>`; click Edit A then Edit B; release; assert the modal name field equals B's.

### H14 ProcedureAccessLogTab: filter selects vs initial load
- Trigger: four `@change="fetchLogs"` :7-28, `clearFilters` :110, onMounted unfiltered load :113.
- Fetch: `fetchLogs` :88-104 unguarded `logs.value` :97; the mount load assigns `logs.value = data` :117 and its finally clears `loading` :129.
- Scenario: the tab opens, the user immediately picks a user filter; the slower unfiltered mount response lands last and replaces the filtered list while the select still shows the filter. Also two quick filter changes.
- Fix: a single `logsSeq` shared by `fetchLogs` and the onMounted fetch (they write the same `logs`/`loading`); the dropdown population in onMounted does not need the guard.
- Test: hold the first (unfiltered) access-log request, choose a user in the filter, wait for filtered logs, release, assert the table remains filtered.

## 3. MEDIUM findings

- **M1 ScheduleView closures** (:1609-1626). `fetchClosures` runs only from `watch(showClosures)`; it is never re-run on month or group change, so with the overlay on, navigating months leaves `monthClosures` on the old month (closure markers missing). It also has the plain race (unguarded `monthClosures.value` :1612/1614). Fix: `closuresSeq` guard, and call `if (showClosures.value) fetchClosures()` next to the other overlay fetches in `fetchSchedule`'s success path. Test: closure in 2099-11, overlay on, navigate months with a held request, assert the marker belongs to the displayed month.
- **M2 ChecklistHistoryView.viewDetail** (:640-646). Unguarded `detailData.value`; reachable only by clicking two rows before the first detail arrives. Fix `detailSeq`.
- **M3 openProcedureModal** (ChecklistHistoryView :750, ChecklistFillView :930). Unguarded `procedurePreview`, and the catch closes the modal for a request nobody cares about. Click procedure A, close, click B. Fix `previewSeq`; in the catch only close when current.
- **M4 ProceduresToLearnView** (`openProcedure` :35, `checkHash` :53). `openProcedure` sets the hash, which fires `hashchange` -> `checkHash`, so the same procedure is fetched twice; `modalLoading=false` runs in finally of superseded calls; the `hashchange` listener is added at setup (:171) and never removed. Fix: single `previewSeq` for both functions, remove the listener in `onUnmounted`.
- **M5 UsersView.openNewbieModal** (:1158-1168). `userNewbiePeriods.value = res.data` unguarded; close the modal and open another user before it resolves. Fix `newbieSeq` or compare `newbieUser.value?.id === user.id`.
- **M6 ProcedureEditorModal.loadData** (:609-626, `watch(show)` :629). Close/reopen mid-load: a stale `loadFromResponse` overwrites a fresh form, and a stale `emit('close')` closes a freshly opened modal. Fix `loadSeq`.
- **M7 ProcedureTreeEditorView.onMoveProcedure** (:163-168, `fetchTree` :179). Two quick drops: the older `GET /api/procedures-tree` landing last shows a pre-move tree. Fix `treeSeq` in `fetchTree`.
- **M8 ChecklistFillView.startFilling shiftInfo** (:713-720). Fires after `step='fill'`; the user can go back and pick another shift before it resolves. Fix `shiftInfoSeq`.
- **M9 MyAvailabilityView.fetchEntries** (:253-259, watched at :323). No params, so no wrong-month data, but a refetch started by month navigation can resolve after `saveEntry`/`deleteEntry`, resurrecting a deleted entry or dropping an added one; the catch toasts for stale requests. Fix `entriesSeq` (mutations should also bump it).
- **M10 ProcedureDetailView.openDiffModal** (:314-348). Awaited fetches, then assigns the diff data and opens the modal regardless of whether the viewing version changed while waiting. Fix `diffSeq`.

Each MEDIUM item is a one-counter fix in the reference style; tests follow the shared recipe (hold the first request, trigger the second, release, assert the second's UI).

## 4. LOW / not a risk
- PlayerSelectionPanel.vue `watch(monthKey)` :318 / `fetchData` :287: unguarded, and the fetch also resets `selectedIds`, but it lives inside AutoFillPanel modals (v-if, aria-modal, fixed overlay) that cover the schedule month controls, so a month change while mounted is not reachable by clicking. Cheap to guard defensively (`dataSeq`).
- AppLayout.vue 60 s alarm poll (:310, :495): no params, both requests return current state; overlap needs a response over 60 s and the data is identical.
- ScheduleView `fetchShiftColors` :1525 (global, param-less), `fetchTemplates` :2081, `current-shift`: any ordering gives the same data; called after each guarded schedule fetch, harmless duplicates.
- UsersView `fetchGroups` :807 (param-less; `selectedGroup` is re-resolved by id, not replaced), `fetchDelegations` :1107 (param-less list).
- DelegationArchiveView/DelegationsManageView `fetchLogs(delId)`: keyed by delegation id, so the same id always returns the same data.
- DashboardView, DelegationsView, ProcedureTagsView, ShiftSettingsView, DelegationsManageView, ProcedureTreeEditorView `fetchData`: single mount fetch (sequential awaits), no filter/param refetch trigger.

## 5. Ruled out (deliberately)
- ScheduleView `fetchSchedule`: guarded (:2050-2077); the `activeGroupId` snap, loading and toast are all guarded. Post-mutation `await fetchSchedule()` callers (generate, clear, shift types, AutoFill `filled`) just supersede older calls.
- ScheduleView `switchGroup`/`createShiftGroup`/`deleteShiftGroup`: only assign state then call the guarded `fetchSchedule`.
- SettlementPeriodsView and MyShiftsView: reference implementations; confirmed guards on data, catch and finally (MyShifts guards both requests with one check after `Promise.all`).
- ProcedureContent `watch(activeTab)`: sets a flag only; Faq/Feedback/Owners/Templates/Diff/TreeNode components: no loads.
- ConfirmModal / useFocusTrap `watch(isOpen)`: focus management, no fetch.
- AutoFillPanel POSTs and ShiftTypeBulkEditor PUT loop: user-initiated mutations guarded by `running`/`saving`, not data loads.
- router `beforeEach`: vue-router drops superseded navigation results, so a late `/api/user` cannot apply to an abandoned navigation.
- LoginView `@input`: clears errors only. ProcedureDetailView `watch(version)` with `procedure.value == null` returns early; a `/procedures/:id` param change without remount would be a staleness issue (no refetch), not a response race, so it is out of scope.

## 6. Guard sharing guidance
- ScheduleView: separate counters for overlays (`delegationsSeq`, `newbiesSeq`, `closuresSeq`, `availabilitySeq`); they write different state and are independent of `scheduleRequestSeq`. Do not reuse `scheduleRequestSeq` (a schedule refetch must not discard a still-valid overlay).
- ChecklistHistoryView, ChecklistFillView, MyAvailabilityView, ProceduresToLearnView: one counter per distinct piece of state, not per file (for example ChecklistFill needs `shiftsSeq`, `confirmationSeq`, `shiftInfoSeq`, `previewSeq`).
- ProcedureAccessLogTab: the mount fetch and `fetchLogs` must share ONE counter (same target state).
- ProceduresToLearnView: `openProcedure` and `checkHash` share one counter.
