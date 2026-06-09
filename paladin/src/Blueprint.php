<?php
declare(strict_types=1);

/**
 * Blueprint — built-in page blueprints (Confluence-style starter templates).
 * Each blueprint provides a key, label, icon, description, a suggested title,
 * and prebuilt HTML body (using the app's existing macro markup). These ship
 * in code; user-defined templates still come from the `templates` table.
 */
final class Blueprint
{
    /** @return array<string,array{name:string,icon:string,color:string,desc:string,title:string,body:string}> */
    public static function all(): array
    {
        return [
            'meeting-notes' => [
                'name' => 'Meeting Notes', 'icon' => 'bi-calendar-event', 'color' => '#2563eb',
                'desc' => 'Agenda, attendees, discussion and action items for a meeting.',
                'title' => 'Meeting Notes — ' . date('M j, Y'),
                'body' => '<p><strong>Date:</strong> ' . date('F j, Y') . ' &nbsp; <strong>Location:</strong> </p>'
                    . '<h2>Attendees</h2><ul><li>@</li></ul>'
                    . '<h2>Agenda</h2><ol><li>Topic one</li><li>Topic two</li></ol>'
                    . '<h2>Discussion</h2><p>Notes…</p>'
                    . '<h2>Action items</h2><ul><li>[ ] Owner — task — due</li></ul>'
                    . '<h2>Decisions</h2><div class="panel panel-info"><div class="panel-icon"><i class="bi bi-info-circle-fill"></i></div><div class="panel-body"><p>Record decisions here.</p></div></div>',
            ],
            'decision' => [
                'name' => 'Decision Record', 'icon' => 'bi-signpost-split', 'color' => '#7c3aed',
                'desc' => 'Capture a decision, its context, options considered and outcome.',
                'title' => 'Decision — ',
                'body' => '<p><span class="lozenge lozenge-yellow">Proposed</span></p>'
                    . '<h2>Context</h2><p>What is the problem or opportunity?</p>'
                    . '<h2>Options considered</h2><table><thead><tr><th>Option</th><th>Pros</th><th>Cons</th></tr></thead><tbody><tr><td>Option A</td><td></td><td></td></tr><tr><td>Option B</td><td></td><td></td></tr></tbody></table>'
                    . '<h2>Decision</h2><div class="panel panel-success"><div class="panel-icon"><i class="bi bi-check-circle-fill"></i></div><div class="panel-body"><p>We will…</p></div></div>'
                    . '<h2>Consequences</h2><p>What becomes easier or harder?</p>',
            ],
            'how-to' => [
                'name' => 'How-to Guide', 'icon' => 'bi-book', 'color' => '#0ea5e9',
                'desc' => 'Step-by-step instructions with prerequisites and outcomes.',
                'title' => 'How to ',
                'body' => '<div class="macro-toc"><div class="macro-toc-title">On this page</div></div>'
                    . '<h2>Overview</h2><p>What this guide covers and who it is for.</p>'
                    . '<h2>Prerequisites</h2><ul><li>…</li></ul>'
                    . '<h2>Steps</h2><ol><li>First step</li><li>Second step</li></ol>'
                    . '<h2>Result</h2><p>What success looks like.</p>'
                    . '<div class="panel panel-warning"><div class="panel-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="panel-body"><p>Watch out for…</p></div></div>',
            ],
            'requirements' => [
                'name' => 'Requirements', 'icon' => 'bi-list-check', 'color' => '#059669',
                'desc' => 'Product/feature requirements with scope, stories and acceptance.',
                'title' => 'Requirements — ',
                'body' => '<h2>Summary</h2><p>One-paragraph description.</p>'
                    . '<h2>Goals &amp; non-goals</h2><ul><li><strong>Goal:</strong> …</li><li><strong>Non-goal:</strong> …</li></ul>'
                    . '<h2>User stories</h2><table><thead><tr><th>As a…</th><th>I want…</th><th>So that…</th><th>Priority</th></tr></thead><tbody><tr><td></td><td></td><td></td><td><span class="lozenge lozenge-red">Must</span></td></tr></tbody></table>'
                    . '<h2>Acceptance criteria</h2><ul><li>[ ] …</li></ul>'
                    . '<h2>Open questions</h2><ul><li>…</li></ul>',
            ],
            'retrospective' => [
                'name' => 'Retrospective', 'icon' => 'bi-arrow-repeat', 'color' => '#db2777',
                'desc' => 'Team retro: what went well, what didn\'t, and actions.',
                'title' => 'Retrospective — ' . date('M j, Y'),
                'body' => '<h2>What went well</h2><ul><li>…</li></ul>'
                    . '<h2>What didn\'t go well</h2><ul><li>…</li></ul>'
                    . '<h2>Ideas to try</h2><ul><li>…</li></ul>'
                    . '<h2>Action items</h2><ul><li>[ ] Owner — action — due</li></ul>',
            ],
            'runbook' => [
                'name' => 'Runbook', 'icon' => 'bi-wrench-adjustable', 'color' => '#ea580c',
                'desc' => 'Operational runbook: triggers, steps, rollback and contacts.',
                'title' => 'Runbook — ',
                'body' => '<div class="panel panel-note"><div class="panel-icon"><i class="bi bi-sticky-fill"></i></div><div class="panel-body"><p><strong>Severity / scope:</strong> …</p></div></div>'
                    . '<h2>When to use</h2><p>Triggers and symptoms.</p>'
                    . '<h2>Procedure</h2><ol><li>Step one</li><li>Step two</li></ol>'
                    . '<h2>Verification</h2><ul><li>[ ] Confirm …</li></ul>'
                    . '<h2>Rollback</h2><p>How to revert safely.</p>'
                    . '<h2>Contacts</h2><p>On-call / escalation.</p>',
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
