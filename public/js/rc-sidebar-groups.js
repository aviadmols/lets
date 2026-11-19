/* rc-sidebar-groups.js — the sidebar's group headings are labels, never accordions.

   The panel sets collapsibleNavigationGroups(false), but Filament still hides a
   group's items whenever the browser REMEMBERS that group as collapsed: its
   sidebar store persists `collapsedGroups` in localStorage and the item list
   keeps `x-show="! groupIsCollapsed(label)"` either way. A group collapsed once,
   in a build that still allowed it, therefore stays empty for good, with no
   chevron left to open it again. That is how a store lost its "Products" item.

   Groups cannot be collapsed here, so a remembered collapse is always stale:
   cleared on load. Registered as a panel asset (head-loaded, survives
   wire:navigate). */

(function () {
    function expandAll() {
        var store = window.Alpine && window.Alpine.store ? window.Alpine.store('sidebar') : null;

        if (store && Array.isArray(store.collapsedGroups) && store.collapsedGroups.length > 0) {
            store.collapsedGroups = [];
        }
    }

    // Alpine may already be running by the time this file executes, or not yet.
    if (window.Alpine && window.Alpine.store && window.Alpine.store('sidebar')) {
        expandAll();
    } else {
        document.addEventListener('alpine:initialized', expandAll);
    }
})();
