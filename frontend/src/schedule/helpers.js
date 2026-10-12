// frontend/src/schedule/helpers.js
//
// Pure helpers of the Schedule screen (ScheduleView.vue and its components/composables): no state,
// no requests. Moved out of the view unchanged.

// Group shifts by shift_group_id for All Groups mode rendering
export function groupShiftsByGroup(shifts) {
  const map = {};
  for (const shift of shifts) {
    const gid = shift.shift_group_id || 'default';
    if (!map[gid]) {
      map[gid] = {
        groupId: gid,
        name: shift.shift_group_name || 'Unknown',
        color: shift.shift_group_color || null,
        shifts: [],
      };
    }
    map[gid].shifts.push(shift);
  }
  return Object.values(map);
}

export function minutesToAngle(minutes) {
  return (minutes / (24 * 60)) * 360;
}

export function polarToCart(cx, cy, r, angleDeg) {
  const rad = ((angleDeg - 90) * Math.PI) / 180;
  return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
}

export function describeArc(cx, cy, r, startAngle, endAngle) {
  if (endAngle - startAngle >= 360) endAngle = startAngle + 359.99;
  const start = polarToCart(cx, cy, r, startAngle);
  const end = polarToCart(cx, cy, r, endAngle);
  const largeArc = endAngle - startAngle > 180 ? 1 : 0;
  return `M ${start.x} ${start.y} A ${r} ${r} 0 ${largeArc} 1 ${end.x} ${end.y}`;
}

export function isOvernightShift(shift) {
  return shift.start_time && shift.end_time && shift.end_time < shift.start_time;
}

export function formatClosureDate(dateStr) {
  const d = new Date((dateStr?.substring?.(0, 10) || dateStr) + 'T12:00:00');
  return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

export function getInitials(name) {
  if (!name) return '';
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}

// The chip a roster card becomes when it is dragged onto a shift (VueDraggable's `clone`).
export function cloneEmployee(original) {
  return {
    user_id: original.id || original.user_id,
    user_name: original.name || original.user_name,
    id: null, // assignment id, to be filled by server
  };
}
