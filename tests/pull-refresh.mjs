import assert from 'node:assert/strict';
import {pullDistance,refreshThreshold} from '../src/pull-gesture.js';
assert.equal(pullDistance(120,60),0,'Horizontal card swipes must not refresh');
assert.equal(pullDistance(0,-180),0,'Scrolling down through content must not refresh');
assert.ok(pullDistance(5,90)<refreshThreshold,'A short pull must not refresh');
assert.ok(pullDistance(5,160)>=refreshThreshold,'A deliberate downward pull reaches the threshold');
assert.equal(pullDistance(0,1000),96,'Overscroll feedback stays within its maximum');
console.log('Pull-to-refresh gesture checks passed.');
