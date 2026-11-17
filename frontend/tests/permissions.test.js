import test from 'node:test';
import assert from 'node:assert/strict';
import { canAccessPage, visibleNavigation, can, roleOf } from '../src/lib/permissions.js';

const user = (role, permissions = []) => ({ role, access_role: role, permissions });
const pages = value => visibleNavigation(value).map(item => item.page);

test('all roles use the same permission-driven navigation configuration', () => {
  assert.deepEqual(pages(user('owner', ['*'])), ['overview', 'records', 'inventory', 'reports', 'more']);
  for (const role of ['staff', 'member']) {
    const member = user(role, ['dashboard.personal', 'sales.read.own', 'sales.create', 'inventory.read', 'telegram.link']);
    assert.deepEqual(pages(member), ['overview', 'records', 'inventory']);
    assert.equal(canAccessPage(member, 'telegram'), true);
    for (const page of ['more', 'business', 'prepare', 'options', 'packs', 'documents', 'team', 'reports']) assert.equal(canAccessPage(member, page), false);
  }
});

test('manager gets only granted reports and hub modules', () => {
  const manager = user('manager', ['dashboard.personal', 'sales.read.own', 'inventory.read', 'reports.operational']);
  assert.deepEqual(pages(manager), ['overview', 'records', 'inventory', 'reports']);
  const telegramManager = { ...manager, permissions: [...manager.permissions, 'telegram.settings'] };
  assert.equal(canAccessPage(telegramManager, 'more'), true);
  assert.equal(canAccessPage(telegramManager, 'business'), false);
  assert.equal(canAccessPage(telegramManager, 'team'), false);
  assert.equal(can(telegramManager, 'inventory.manage'), false);
});

test('workspace membership permissions replace a previous owner session', () => {
  assert.equal(can(user('owner'), 'reports.financial'), true);
  const membership = user('member', ['dashboard.personal', 'inventory.read']);
  assert.equal(can(membership, 'reports.financial'), false);
  assert.deepEqual(pages(membership), ['overview', 'inventory']);
  assert.equal(roleOf({ role: 'staff', job_role: 'manager' }), 'manager');
});

test('ordinary staff cannot use hub pages even if frontend permissions are manipulated', () => {
  const manipulated = user('staff', ['dashboard.personal', 'financing', 'team.manage', 'telegram.settings']);
  for (const page of ['more', 'business', 'team', 'prepare', 'documents']) assert.equal(canAccessPage(manipulated, page), false);
});
