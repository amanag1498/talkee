// server.js
require('dotenv').config();

const http    = require('http');
const express = require('express');
const cors    = require('cors');
const { Server } = require('socket.io');
const Redis   = require('ioredis');
const axios   = require('axios');
// ----- LiveKit token sanity endpoint -----
const jwt = require('jsonwebtoken');
const { randomUUID } = require('crypto');


// ------------ Config ------------

const LK_API_KEY = process.env.LK_API_KEY || '';
const LK_API_SECRET = process.env.LK_API_SECRET || '';
const PORT                = Number(process.env.PORT || 3001);
const API_BASE            = process.env.API_BASE || 'http://127.0.0.1:8000/api';
const VERIFY_WITH_LARAVEL = String(process.env.VERIFY_WITH_LARAVEL || 'true') === 'true';
const REDIS_URL           = process.env.REDIS_URL || 'redis://127.0.0.1:6379';
const HEARTBEAT_MS        = Number(process.env.HEARTBEAT_MS || 30000);
const PRESENCE_SNAPSHOT_MODE = (process.env.PRESENCE_SNAPSHOT_MODE || 'count').toLowerCase();
const ROOMS_SNAPSHOT_LIMIT   = Number(process.env.ROOMS_SNAPSHOT_LIMIT || 100);
const APP_CONFIG_CACHE_TTL_MS = Number(process.env.APP_CONFIG_CACHE_TTL_MS || 60000);
const APP_CONFIG_POLL_MS      = Number(process.env.APP_CONFIG_POLL_MS || 60000);
const MODERATION_CACHE_TTL_MS = Number(process.env.MODERATION_CACHE_TTL_MS || 60000);
const MODERATION_CACHE_POLL_MS = Number(process.env.MODERATION_CACHE_POLL_MS || 60000);
const WS_VERIFY_CACHE_TTL_MS = Number(process.env.WS_VERIFY_CACHE_TTL_MS || 30000);
const ROOM_CHAT_MAX_LENGTH = Number(process.env.ROOM_CHAT_MAX_LENGTH || 250);
const ROOM_CHAT_WINDOW_MS = Number(process.env.ROOM_CHAT_WINDOW_MS || 8000);
const ROOM_CHAT_MAX_PER_WINDOW = Number(process.env.ROOM_CHAT_MAX_PER_WINDOW || 5);
const ROOMS_LEAVE_GRACE_MS = Number(process.env.ROOMS_LEAVE_GRACE_MS || 45000);
const WS_INTERNAL_KEY = process.env.WS_INTERNAL_KEY || '';

const app = express();
app.use(cors());
const server = http.createServer(app);
const io     = new Server(server, { cors: { origin: '*', methods: ['GET','POST'] } });

const redis = new Redis(REDIS_URL); // data
const sub   = new Redis(REDIS_URL); // pub/sub
const api   = axios.create({ baseURL: API_BASE, timeout: 5000 });
const pendingRoomLeaveTimers = new Map();

const nowISO = () => new Date().toISOString();

function publicApiOriginForSocket(socket) {
  const configured = String(process.env.PUBLIC_API_BASE_URL || '').trim();
  if (configured) {
    return configured.replace(/\/+$/, '').replace(/\/api$/, '');
  }

  const hostHeader = String(
    socket?.handshake?.headers?.['x-forwarded-host']
      || socket?.handshake?.headers?.host
      || '',
  ).trim();
  if (!hostHeader) {
    return '';
  }

  const forwardedProto = String(
    socket?.handshake?.headers?.['x-forwarded-proto'] || '',
  ).trim();
  const apiBase = new URL(API_BASE);
  const protocol = forwardedProto || apiBase.protocol.replace(':', '') || 'http';
  const hostname = hostHeader.split(':')[0];
  const port = apiBase.port ? `:${apiBase.port}` : '';

  return `${protocol}://${hostname}${port}`;
}

function defaultAppConfig() {
  return {
    enable_premium_theme_variants: false,
    premium_theme_variant: 'default',
    maintenance_mode_enabled: false,
    force_app_upgrade_enabled: false,
    android_min_version_code: 1,
    android_min_version_name: '1.0.0',
    android_update_message: 'Please update Talkee to continue using the app.',
    features: {
      audio_rooms_enabled: true,
      video_rooms_enabled: true,
      pk_battles_enabled: true,
      gifts_enabled: true,
      subscriptions_enabled: true,
      entry_effects_enabled: true,
      wallet_recharge_enabled: true,
      host_calling_enabled: true,
      teen_patti_enabled: false,
      greedy_enabled: false,
      video_room_games_enabled: false,
    },
  };
}

function normalizeAppConfig(payload) {
  const input = payload && typeof payload === 'object' ? payload : {};
  const features = input.features && typeof input.features === 'object' ? input.features : {};
  const defaults = defaultAppConfig();
  return {
    ...defaults,
    ...input,
    features: {
      ...defaults.features,
      ...features,
    },
  };
}

let appConfigCache = {
  fetchedAt: 0,
  data: defaultAppConfig(),
};

function emptyModerationCache() {
  return {
    fetchedAt: 0,
    available: false,
    rules: [],
    hostBlocks: new Map(),
  };
}

let moderationCache = emptyModerationCache();
const verifiedUserCache = new Map();

async function getAppConfig(force = false) {
  const now = Date.now();
  if (!force && appConfigCache.fetchedAt && (now - appConfigCache.fetchedAt) < APP_CONFIG_CACHE_TTL_MS) {
    return appConfigCache.data;
  }

  try {
    const { data } = await api.get('/app-config');
    appConfigCache = {
      fetchedAt: now,
      data: normalizeAppConfig(data?.data),
    };
  } catch (e) {
    console.error('[config][ERR]', nowISO(), `app-config fetch failed: ${e.message}`);
    if (!appConfigCache.fetchedAt) {
      appConfigCache = { fetchedAt: now, data: defaultAppConfig() };
    }
  }

  return appConfigCache.data;
}

function featureEnabled(flagKey) {
  return !!appConfigCache.data?.features?.[flagKey];
}

function roomTypeFeatureEnabled(roomType) {
  if (String(roomType || 'video') === 'audio') {
    return featureEnabled('audio_rooms_enabled');
  }
  return featureEnabled('video_rooms_enabled');
}

function isAndroidClientSupported(platform, versionCode) {
  if (String(platform || '').trim().toLowerCase() !== 'android') {
    return false;
  }
  if (!appConfigCache.data.force_app_upgrade_enabled) {
    return true;
  }
  return Number(versionCode || 0) >= Number(appConfigCache.data.android_min_version_code || 1);
}

function featureErrorPayload(code, message, extra = {}) {
  return {
    ok: false,
    error: code,
    message,
    ...extra,
  };
}

function internalApiHeaders() {
  return WS_INTERNAL_KEY ? { 'X-WS-Internal-Key': WS_INTERNAL_KEY } : {};
}

function normalizeModerationRule(rule) {
  const input = rule && typeof rule === 'object' ? rule : {};
  return {
    id: Number(input.id || 0),
    rule_key: String(input.rule_key || '').trim(),
    rule_type: String(input.rule_type || '').trim().toLowerCase(),
    pattern: input.pattern == null ? null : String(input.pattern).trim(),
    threshold: Number.isFinite(Number(input.threshold)) ? Number(input.threshold) : null,
    action: String(input.action || '').trim().toLowerCase(),
    duration_minutes: Number.isFinite(Number(input.duration_minutes))
      ? Number(input.duration_minutes)
      : null,
    severity: String(input.severity || 'low').trim().toLowerCase(),
    is_active: Boolean(input.is_active),
  };
}

function buildHostBlocksIndex(hostBlocks) {
  const map = new Map();
  if (!hostBlocks || typeof hostBlocks !== 'object') {
    return map;
  }
  for (const [hostUserId, blockedUsers] of Object.entries(hostBlocks)) {
    const normalizedHostUserId = Number(hostUserId || 0);
    if (!normalizedHostUserId || !Array.isArray(blockedUsers)) {
      continue;
    }
    map.set(
      normalizedHostUserId,
      new Set(
        blockedUsers
          .map((id) => Number(id || 0))
          .filter((id) => Number.isFinite(id) && id > 0),
      ),
    );
  }
  return map;
}

async function getModerationSnapshot(force = false) {
  const now = Date.now();
  if (!force && moderationCache.fetchedAt && (now - moderationCache.fetchedAt) < MODERATION_CACHE_TTL_MS) {
    return moderationCache;
  }

  try {
    const { data } = await api.get('/ws/moderation/snapshot', {
      headers: internalApiHeaders(),
    });
    const payload = data?.data || {};
    moderationCache = {
      fetchedAt: now,
      available: true,
      rules: Array.isArray(payload.rules)
        ? payload.rules.map(normalizeModerationRule).filter((rule) => rule.is_active)
        : [],
      hostBlocks: buildHostBlocksIndex(payload.host_blocks),
    };
  } catch (e) {
    console.error('[moderation][ERR]', nowISO(), `snapshot fetch failed: ${e.message}`);
    if (!moderationCache.fetchedAt) {
      moderationCache = emptyModerationCache();
      moderationCache.fetchedAt = now;
    }
  }

  return moderationCache;
}

function invalidateModerationCache() {
  moderationCache.fetchedAt = 0;
}

function isUserBlockedByHost(hostUserId, userId) {
  const normalizedHostUserId = Number(hostUserId || 0);
  const normalizedUserId = Number(userId || 0);
  if (!normalizedHostUserId || !normalizedUserId || normalizedHostUserId === normalizedUserId) {
    return false;
  }
  return moderationCache.hostBlocks.get(normalizedHostUserId)?.has(normalizedUserId) === true;
}

// ------------ Redis Keys ------------
const presenceHbKey = (userId) => `presence:hb:${userId}`;
const presenceSet   = 'presence:online';

const roomKey       = (rid) => `rooms:room:${rid}`;
const roomsLiveSet  = 'rooms:live';

// ------------ Startup Logs ------------
console.log('[presence]', nowISO(), `Presence WS on :${PORT} (ns: /presence)`);
console.log('[rooms]   ', nowISO(), `Rooms WS on     :${PORT} (ns: /rooms)`);
console.log('[common]  ', nowISO(), `Verify with Laravel: ${VERIFY_WITH_LARAVEL ? 'ON' : 'OFF'}`);
console.log('[common]  ', nowISO(), `API_BASE: ${API_BASE}`);
console.log('[common]  ', nowISO(), `Redis: ${REDIS_URL}`);
console.log('[common]  ', nowISO(), `App config cache TTL: ${APP_CONFIG_CACHE_TTL_MS}ms`);
console.log('[common]  ', nowISO(), `App config poll interval: ${APP_CONFIG_POLL_MS}ms`);
console.log('[common]  ', nowISO(), `Moderation cache TTL: ${MODERATION_CACHE_TTL_MS}ms`);
console.log('[common]  ', nowISO(), `Moderation cache poll interval: ${MODERATION_CACHE_POLL_MS}ms`);

// ------------ Auth helpers ------------
async function verifyUserFromLaravel(token, socket = null) {
  if (!token) return null;
  const publicOrigin = publicApiOriginForSocket(socket);
  const cacheKey = `${token}|${publicOrigin}`;
  const cached = verifiedUserCache.get(cacheKey);
  const now = Date.now();
  if (cached && cached.expiresAt > now) {
    return cached.user;
  }
  if (cached) {
    verifiedUserCache.delete(cacheKey);
  }
  try {
    const { data } = await api.get('/ws/verify', {
      headers: {
        Authorization: `Bearer ${token}`,
        ...(publicOrigin ? { 'X-Public-Origin': publicOrigin } : {}),
      },
    });
    if (!data || !data.id) return null;
    const user = {
      id: Number(data.id),
      name: data.name || `User#${data.id}`,
      blocked: !!data.blocked,
      avatar_url: data.avatar_url || null,
      profile_frame: data.profile_frame || null,
      level: Number.isFinite(Number(data.level)) ? Number(data.level) : null,
      is_vip: !!data.is_vip,
      active_theme_key: data.active_theme_key || 'midnight',
      roles: Array.isArray(data.roles) ? data.roles : [],
    };
    verifiedUserCache.set(cacheKey, {
      user,
      expiresAt: now + WS_VERIFY_CACHE_TTL_MS,
    });
    return user;
  } catch (e) {
    console.error('[auth][ERR]', nowISO(), `ws/verify failed: ${e.message}`);
    verifiedUserCache.delete(cacheKey);
    return null;
  }
}

async function refreshSocketUserFromLaravel(socket) {
  if (!VERIFY_WITH_LARAVEL || !socket?.authToken) {
    return socket?.user || null;
  }
  const freshUser = await verifyUserFromLaravel(socket.authToken, socket);
  if (!freshUser) {
    return socket?.user || null;
  }
  socket.user = {
    ...(socket.user || {}),
    ...freshUser,
  };
  return socket.user;
}

async function moderationJoinCheck(token, roomId) {
  if (!VERIFY_WITH_LARAVEL || !token || !roomId) {
    return { ok: true, allow: true };
  }
  try {
    const { data } = await api.post('/ws/rooms/join-check', {
      room_id: String(roomId),
    }, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return data || { ok: true, allow: true };
  } catch (e) {
    console.error('[rooms][ERR]', nowISO(), `join-check failed: ${e.message}`);
    return {
      ok: false,
      allow: false,
      reason: 'Unable to validate room access right now.',
      code: 'MODERATION_CHECK_FAILED',
    };
  }
}

async function moderationChatCheck(token, roomId, message) {
  if (!VERIFY_WITH_LARAVEL || !token || !roomId) {
    return { ok: true, allow: true, message };
  }
  try {
    const { data } = await api.post('/ws/rooms/chat-check', {
      room_id: String(roomId),
      message: String(message || ''),
    }, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return data || { ok: true, allow: true, message };
  } catch (e) {
    console.error('[rooms][ERR]', nowISO(), `chat-check failed: ${e.message}`);
    return {
      ok: false,
      allow: false,
      action: 'warn',
      message: 'Unable to validate chat moderation right now.',
      code: 'MODERATION_CHECK_FAILED',
    };
  }
}

function devUser(socket) {
  const uid = socket.handshake?.auth?.userId || socket.handshake?.query?.userId;
  return uid
    ? {
        id: parseInt(uid, 10),
        name: `User#${uid}`,
        blocked: false,
        avatar_url: null,
        level: null,
        is_vip: false,
        active_theme_key: 'midnight',
        roles: [],
      }
    : null;
}

function makeAuthMiddleware(_namespaceName) {
  return async function auth(socket, next) {
    try {
      const clientPlatform = String(
        socket.handshake?.auth?.platform ||
        socket.handshake?.headers?.['x-client-platform'] ||
        ''
      ).trim().toLowerCase();
      const clientVersionCode = Number(
        socket.handshake?.auth?.app_version_code ||
        socket.handshake?.headers?.['x-app-version-code'] ||
        0
      );

      let token = null;
      let userPromise = null;
      if (VERIFY_WITH_LARAVEL) {
        token =
          socket.handshake.auth?.token ||
          socket.handshake.headers?.authorization?.replace(/^Bearer\s+/i, '') ||
          socket.handshake.headers?.['x-api-token'];
        userPromise = verifyUserFromLaravel(token, socket);
      } else {
        userPromise = Promise.resolve(devUser(socket));
      }

      const [_, user] = await Promise.all([
        getAppConfig(),
        userPromise,
      ]);

      if (appConfigCache.data.maintenance_mode_enabled) {
        return next(new Error('maintenance_mode'));
      }

      if (!isAndroidClientSupported(clientPlatform, clientVersionCode)) {
        if (clientPlatform !== 'android') {
          return next(new Error('unsupported_client_platform'));
        }
        return next(new Error('force_upgrade'));
      }
      if (!user) return next(new Error('unauthorized')); // keep same unauthorized text
      if (user.blocked) return next(new Error('blocked'));

      if (_namespaceName === '/calls' && !featureEnabled('host_calling_enabled')) {
        return next(new Error('host_calling_disabled'));
      }

      if (_namespaceName === '/rooms'
        && !featureEnabled('audio_rooms_enabled')
        && !featureEnabled('video_rooms_enabled')) {
        return next(new Error('live_rooms_disabled'));
      }

      if (_namespaceName === '/games'
        && !featureEnabled('teen_patti_enabled')
        && !featureEnabled('greedy_enabled')) {
        return next(new Error('games_disabled'));
      }

      socket.user = user;
      socket.authToken = token;
      return next();
    } catch {
      return next(new Error('unauthorized'));
    }
  };
}

// ===================================================================
//                 ✅ Namespace-aware socket bookkeeping
// ===================================================================
// Map: userId -> Map<namespaceName, Set<Socket>>
const socketsByUserByNs = new Map();
// Map: socket.id -> { uid, ns, deviceId }
const userBySocketId = new Map();

function addSocketMap(socket) {
  const uid = Number(socket.user.id);
  const ns  = socket.nsp.name; // '/presence' or '/rooms'
  const deviceId = String(
    socket.handshake?.auth?.device_id ||
    socket.handshake?.headers?.['x-device-id'] ||
    ''
  ).trim();
  const platform = String(
    socket.handshake?.auth?.platform ||
    socket.handshake?.headers?.['x-client-platform'] ||
    ''
  ).trim().toLowerCase();
  const appVersion = String(
    socket.handshake?.auth?.app_version ||
    socket.handshake?.headers?.['x-app-version'] ||
    ''
  ).trim();
  const appVersionCode = Number(
    socket.handshake?.auth?.app_version_code ||
    socket.handshake?.headers?.['x-app-version-code'] ||
    0
  );
  let nsMap = socketsByUserByNs.get(uid);
  if (!nsMap) { nsMap = new Map(); socketsByUserByNs.set(uid, nsMap); }
  let set = nsMap.get(ns);
  if (!set) { set = new Set(); nsMap.set(ns, set); }
  set.add(socket);
  userBySocketId.set(socket.id, { uid, ns, deviceId, platform, appVersion, appVersionCode });
}

function removeSocketMap(socket) {
  const info = userBySocketId.get(socket.id);
  if (!info) return;
  const { uid, ns } = info;
  const nsMap = socketsByUserByNs.get(uid);
  if (nsMap) {
    const set = nsMap.get(ns);
    if (set) {
      set.delete(socket);
      if (set.size === 0) nsMap.delete(ns);
    }
    if (nsMap.size === 0) socketsByUserByNs.delete(uid);
  }
  userBySocketId.delete(socket.id);
}

// Send an event to all sockets of a given user (across ALL namespaces)
function emitToUser(userId, event, data) {
  userId = Number(userId);
  const nsMap = socketsByUserByNs.get(userId);
  if (!nsMap) return 0;
  let n = 0;
  for (const set of nsMap.values()) {
    for (const s of set) {
      try { s.emit(event, data); n++; } catch {}
    }
  }
  return n;
}

// ✅ Kick all other sockets for this user **only within the given namespace**
function kickOtherSocketsInNs(userId, nsName, exceptSocketId, reason = 'new_login') {
  userId = Number(userId);
  const nsMap = socketsByUserByNs.get(userId);
  if (!nsMap) return;
  const set = nsMap.get(nsName);
  if (!set) return;
  const currentInfo = userBySocketId.get(exceptSocketId);
  const currentDeviceId = String(currentInfo?.deviceId || '').trim();
  for (const s of [...set]) {
    if (s.id === exceptSocketId) continue;
    const targetInfo = userBySocketId.get(s.id);
    const targetDeviceId = String(targetInfo?.deviceId || '').trim();
    if (currentDeviceId && targetDeviceId && currentDeviceId === targetDeviceId) {
      console.log('[socket][DUP][SKIP_SAME_DEVICE]', nowISO(), JSON.stringify({
        user_id: userId,
        namespace: nsName,
        old_socket_id: s.id,
        keep_socket_id: exceptSocketId,
        device_id: currentDeviceId,
        reason,
      }));
      continue;
    }
    console.log('[socket][DUP]', nowISO(), JSON.stringify({
      user_id: userId,
      namespace: nsName,
      old_socket_id: s.id,
      keep_socket_id: exceptSocketId,
      reason,
    }));
    try { s.emit('auth:logout', { reason }); } catch {}
    try { s.disconnect(true); } catch {}
  }
}

function countSocketsInNs(userId, nsName) {
  userId = Number(userId);
  const nsMap = socketsByUserByNs.get(userId);
  if (!nsMap) return 0;
  return nsMap.get(nsName)?.size || 0;
}

function hasPresenceCarrier(userId) {
  return countSocketsInNs(userId, '/presence') > 0 || countSocketsInNs(userId, '/calls') > 0;
}

async function syncSocketPresenceWithLaravel(token, status) {
  if (!VERIFY_WITH_LARAVEL || !token) return;
  try {
    await api.post('/ws/presence', { socket_status: status }, {
      headers: { Authorization: `Bearer ${token}` },
    });
  } catch (e) {
    console.error('[presence][ERR]', nowISO(), `sync socket_status=${status} failed:`, e.message);
  }
}

function roomLeaveTimerKey(userId, roomId) {
  return `${Number(userId || 0)}:${String(roomId || '')}`;
}

function cancelPendingRoomLeave(userId, roomId) {
  const key = roomLeaveTimerKey(userId, roomId);
  const timer = pendingRoomLeaveTimers.get(key);
  if (timer) {
    clearTimeout(timer);
    pendingRoomLeaveTimers.delete(key);
  }
}

async function syncRoomLeaveWithLaravel(token, roomId) {
  if (!token || !roomId) {
    return;
  }
  try {
    await api.post(`/live/rooms/${roomId}/leave`, {}, {
      headers: {
        Authorization: `Bearer ${token}`,
        ...internalApiHeaders(),
      },
    });
    console.log('[rooms][SYNC]', nowISO(), JSON.stringify({
      action: 'leave_synced',
      room_id: roomId,
    }));
  } catch (e) {
    console.error('[rooms][ERR]', nowISO(), `sync leave room=${roomId} failed:`, e.message);
  }
}

function schedulePendingRoomLeave({ userId, roomId, token, reason }) {
  if (!userId || !roomId || !token) {
    return;
  }
  cancelPendingRoomLeave(userId, roomId);
  const key = roomLeaveTimerKey(userId, roomId);
  const timer = setTimeout(async () => {
    pendingRoomLeaveTimers.delete(key);
    if (countSocketsInNs(userId, '/rooms') > 0) {
      return;
    }
    await syncRoomLeaveWithLaravel(token, roomId);
  }, ROOMS_LEAVE_GRACE_MS);
  pendingRoomLeaveTimers.set(key, timer);
  console.log('[rooms][SYNC]', nowISO(), JSON.stringify({
    action: 'leave_scheduled',
    room_id: roomId,
    user_id: Number(userId || 0),
    grace_ms: ROOMS_LEAVE_GRACE_MS,
    reason: reason || 'disconnect',
  }));
}

function disconnectNamespace(namespace, reason, payload) {
  for (const socket of namespace.sockets.values()) {
    try { socket.emit('feature:error', payload); } catch {}
    try { socket.disconnect(true); } catch {}
  }
}

function hasAnyActiveSockets() {
  return presenceNs.sockets.size > 0 || roomsNs.sockets.size > 0 || callsNs.sockets.size > 0 || gamesNs.sockets.size > 0;
}

function disconnectUnsupportedSockets(reason, predicate, payloadBuilder) {
  for (const [socketId, info] of userBySocketId.entries()) {
    if (!predicate(info)) continue;
    const ns = info.ns === '/presence'
      ? presenceNs
      : info.ns === '/rooms'
        ? roomsNs
        : info.ns === '/calls'
          ? callsNs
          : info.ns === '/games'
            ? gamesNs
          : null;
    const socket = ns?.sockets.get(socketId);
    if (!socket) continue;
    try { socket.emit('feature:error', payloadBuilder(info)); } catch {}
    try { socket.disconnect(true); } catch {}
  }
}

// UPDATED: emit a clear logout event, then disconnect (across ALL namespaces)
async function forceLogout(userId, reason = 'blocked') {
  userId = Number(userId);
  const nsMap = socketsByUserByNs.get(userId);
  if (nsMap) {
    for (const set of nsMap.values()) {
      for (const s of [...set]) {
        try { s.emit('auth:logout', { reason }); } catch {}
        try { s.disconnect(true); } catch {}
      }
    }
  }
  await redis.del(presenceHbKey(userId));
  await redis.srem(presenceSet, String(userId));
  presenceNs.emit('presence:delta', { userId, status: 'offline', at: nowISO(), reason });
  presenceNs.emit('presence:count', await redis.scard(presenceSet));
}

// ===================================================================
//                            PRESENCE NS
// ===================================================================
const presenceNs = io.of('/presence');
presenceNs.use(makeAuthMiddleware('/presence'));

presenceNs.on('connection', (socket) => {
  addSocketMap(socket);
  const userId = Number(socket.user.id);

  console.log('[presence][CONN]', nowISO(), `client connected sid=${socket.id} user=${userId}`);

  // ✅ Enforce single active device per NAMESPACE: only kicks other /presence sockets
  kickOtherSocketsInNs(userId, '/presence', socket.id, 'new_login');
  syncSocketPresenceWithLaravel(socket.authToken, 'online');

  socket.on('presence:online', async () => {
    await redis.set(presenceHbKey(userId), Date.now(), 'PX', HEARTBEAT_MS);
    await redis.sadd(presenceSet, String(userId));
    syncSocketPresenceWithLaravel(socket.authToken, 'online');
    presenceNs.emit('presence:delta', { userId, status: 'online', at: nowISO() });
    presenceNs.emit('presence:count', await redis.scard(presenceSet));
  });

  socket.on('presence:subscribe', async () => {
    if (PRESENCE_SNAPSHOT_MODE === 'list') {
      const ids = await redis.smembers(presenceSet);
      socket.emit('presence:snapshot', ids.map((id) => ({ userId: Number(id) })));
    } else {
      socket.emit('presence:snapshot', { count: await redis.scard(presenceSet) });
    }
  });

  socket.on('presence:ping', async () => {
    await redis.set(presenceHbKey(userId), Date.now(), 'PX', HEARTBEAT_MS);
  });

  socket.on('presence:offline', async () => {
    await redis.del(presenceHbKey(userId));
    await redis.srem(presenceSet, String(userId));
    syncSocketPresenceWithLaravel(socket.authToken, 'offline');
    presenceNs.emit('presence:delta', { userId, status: 'offline', at: nowISO() });
    presenceNs.emit('presence:count', await redis.scard(presenceSet));
  });

  socket.on('disconnect', () => {
    removeSocketMap(socket);
    console.log('[presence][CONN]', nowISO(), `disconnect sid=${socket.id} user=${userId}`);
    if (!hasPresenceCarrier(userId)) {
      syncSocketPresenceWithLaravel(socket.authToken, 'offline');
    }
    // rely on TTL sweeper
  });
});

// presence sweeper
setInterval(async () => {
  try {
    const ids = await redis.smembers(presenceSet);
    if (!ids.length) return;
    for (const id of ids) {
      const alive = await redis.get(presenceHbKey(id));
      if (!alive) {
        await redis.srem(presenceSet, id);
        console.log('[presence][STALE]', nowISO(), JSON.stringify({
          user_id: Number(id),
          reason: 'heartbeat_expired',
        }));
        presenceNs.emit('presence:delta', { userId: Number(id), status: 'offline', at: nowISO(), reason: 'stale' });
      }
    }
    presenceNs.emit('presence:count', await redis.scard(presenceSet));
  } catch (e) {
    console.error('[presence][ERR]', nowISO(), 'sweeper:', e.message);
  }
}, 5000);

// ===================================================================
//                           ROOMS  NS  (with DEBUG)
// ===================================================================
const roomsNs = io.of('/rooms');
roomsNs.use(makeAuthMiddleware('/rooms'));
const callsNs = io.of('/calls');
callsNs.use(makeAuthMiddleware('/calls'));
const gamesNs = io.of('/games');
gamesNs.use(makeAuthMiddleware('/games'));

let teenPattiSnapshotCache = null;
let teenPattiSnapshotHash = '';
let greedySnapshotCache = null;
let greedySnapshotHash = '';

function hashTeenPattiSnapshot(payload) {
  try {
    return JSON.stringify(payload || {});
  } catch {
    return '';
  }
}

async function fetchTeenPattiSnapshotInternal(force = false) {
  await getAppConfig();
  if (!featureEnabled('teen_patti_enabled')) {
    teenPattiSnapshotCache = null;
    teenPattiSnapshotHash = '';
    return null;
  }

  try {
    const { data } = await api.get('/ws/games/teen-patti/snapshot', {
      headers: internalApiHeaders(),
    });
    const payload = data && typeof data === 'object' ? data : null;
    if (!payload?.ok) {
      return null;
    }
    const nextHash = hashTeenPattiSnapshot(payload);
    const changed = force || nextHash !== teenPattiSnapshotHash;
    teenPattiSnapshotCache = payload;
    teenPattiSnapshotHash = nextHash;

    if (changed) {
      gamesNs.emit('teen_patti:snapshot', payload);
    }

    return payload;
  } catch (e) {
    console.error('[games][ERR]', nowISO(), `teen patti snapshot fetch failed: ${e.message}`);
    return teenPattiSnapshotCache;
  }
}

function hashGreedySnapshot(payload) {
  try {
    return JSON.stringify(payload || {});
  } catch {
    return '';
  }
}

async function fetchGreedySnapshotInternal(force = false) {
  await getAppConfig();
  if (!featureEnabled('greedy_enabled')) {
    greedySnapshotCache = null;
    greedySnapshotHash = '';
    return null;
  }

  try {
    const { data } = await api.get('/ws/games/greedy/snapshot', {
      headers: internalApiHeaders(),
    });
    const payload = data && typeof data === 'object' ? data : null;
    if (!payload?.ok) {
      return null;
    }
    const nextHash = hashGreedySnapshot(payload);
    const changed = force || nextHash !== greedySnapshotHash;
    greedySnapshotCache = payload;
    greedySnapshotHash = nextHash;

    if (changed) {
      gamesNs.emit('greedy:snapshot', payload);
    }

    return payload;
  } catch (e) {
    console.error('[games][ERR]', nowISO(), `greedy snapshot fetch failed: ${e.message}`);
    return greedySnapshotCache;
  }
}

function socketsInRoom(roomId) {
  const set = roomsNs.adapter.rooms.get(roomId);
  return set ? set.size : 0;
}

function isAudioRoom(doc) {
  return String(doc?.room_type || 'video') === 'audio';
}

function audienceCountForRoom(doc) {
  if (isAudioRoom(doc)) {
    return Number(doc.listener_count ?? doc.audience_count ?? 0);
  }
  return Number(doc.viewer_count ?? doc.audience_count ?? 0);
}

const roomChatRateState = new Map();

function sanitizeRoomMessage(input) {
  const raw = String(input ?? '');
  return raw
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function withinRoomChatRateLimit(userId) {
  const uid = Number(userId || 0);
  if (!uid) return false;
  const now = Date.now();
  const cutoff = now - ROOM_CHAT_WINDOW_MS;
  const bucket = roomChatRateState.get(uid) || [];
  const recent = bucket.filter((ts) => ts >= cutoff);
  if (recent.length >= ROOM_CHAT_MAX_PER_WINDOW) {
    roomChatRateState.set(uid, recent);
    return false;
  }
  recent.push(now);
  roomChatRateState.set(uid, recent);
  return true;
}

function socketHasRole(socket, roleName) {
  const roles = Array.isArray(socket.user?.roles) ? socket.user.roles : [];
  return roles.includes(roleName);
}

function roomMessageErrorPayload(code, message, roomId) {
  return {
    code,
    message,
    room_id: String(roomId || ''),
    at: nowISO(),
  };
}

function moderationReasonFromRule(rule) {
  return `Auto moderation: ${String(rule?.rule_key || 'rule')}`;
}

function matchesRulePattern(message, pattern) {
  const normalizedPattern = String(pattern || '').trim().toLowerCase();
  if (!normalizedPattern) {
    return false;
  }
  return String(message || '').toLowerCase().includes(normalizedPattern);
}

function moderationContainsLink(message) {
  return /https?:\/\/|www\.|[a-z0-9\-_]+\.[a-z]{2,}/i.test(String(message || ''));
}

async function appendAndFetchModerationTimeline(roomId, userId, message, windowSeconds) {
  const normalizedRoomId = String(roomId || '').trim();
  const normalizedUserId = Number(userId || 0);
  if (!normalizedRoomId || !normalizedUserId) {
    return [];
  }

  const lowerMessage = String(message || '').toLowerCase();
  const now = Date.now();
  const minScore = now - (windowSeconds * 1000);
  const key = `moderation:chat:${normalizedRoomId}:${normalizedUserId}`;
  const entry = JSON.stringify({
    t: now,
    m: lowerMessage,
    n: randomUUID(),
  });

  const pipeline = redis.multi();
  pipeline.zadd(key, now, entry);
  pipeline.zremrangebyscore(key, 0, minScore);
  pipeline.zrange(key, 0, -1);
  pipeline.expire(key, Math.max(5, Math.ceil(windowSeconds)));
  const results = await pipeline.exec();
  const rawRows = Array.isArray(results?.[2]?.[1]) ? results[2][1] : [];

  return rawRows
    .map((value) => {
      try {
        return JSON.parse(String(value || '{}'));
      } catch (_) {
        return null;
      }
    })
    .filter((row) => row && Number.isFinite(Number(row.t)) && typeof row.m === 'string')
    .map((row) => ({
      t: Number(row.t),
      m: String(row.m),
    }));
}

async function clearModerationTimeline(roomId, userId) {
  const normalizedRoomId = String(roomId || '').trim();
  const normalizedUserId = Number(userId || 0);
  if (!normalizedRoomId || !normalizedUserId) {
    return;
  }
  await redis.del(`moderation:chat:${normalizedRoomId}:${normalizedUserId}`);
}

function messageMatchesRateRule(timeline, message, rule) {
  const threshold = Math.max(2, Number(rule?.threshold || 3));
  const lowerMessage = String(message || '').toLowerCase();
  const sameCount = timeline.filter((row) => row.m === lowerMessage).length;
  if (rule?.rule_type === 'spam') {
    return sameCount >= threshold;
  }
  return timeline.length >= threshold;
}

async function persistModerationAction(actionType, payload) {
  const { data } = await api.post('/ws/moderation/persist-chat-action', {
    action_type: actionType,
    ...payload,
  }, {
    headers: internalApiHeaders(),
  });
  return data || { ok: false };
}

async function evaluateRoomModeration(roomDoc, socket, message) {
  const sanitizedMessage = sanitizeRoomMessage(message);
  const roomId = String(roomDoc?.room_id || roomDoc?.id || '').trim();
  if (!sanitizedMessage) {
    return {
      allow: false,
      action: 'warn',
      message: 'Message cannot be empty.',
    };
  }

  const snapshot = await getModerationSnapshot();
  if (!snapshot.available) {
    return moderationChatCheck(socket.authToken, roomId, sanitizedMessage);
  }

  const roomType = String(roomDoc?.room_type || 'video').trim().toLowerCase();
  const hostUserId = Number(roomDoc?.host_id || 0);
  const senderId = Number(socket.user?.id || 0);

  if (isUserBlockedByHost(hostUserId, senderId)) {
    return {
      allow: false,
      action: 'block',
      message: 'You were blocked by this host.',
    };
  }

  const rules = moderationCache.rules;
  const spamRules = rules.filter((rule) => rule.rule_type === 'spam' || rule.rule_type === 'flooding');
  let timeline = null;
  if (spamRules.length > 0) {
    const maxWindowSeconds = Math.max(
      5,
      ...spamRules.map((rule) => Math.max(5, Number(rule.duration_minutes || 1) * 60)),
    );
    timeline = await appendAndFetchModerationTimeline(roomId, senderId, sanitizedMessage, maxWindowSeconds);
  }

  for (const rule of rules) {
    const matched = (() => {
      switch (rule.rule_type) {
        case 'bad_word':
        case 'custom':
          return matchesRulePattern(sanitizedMessage, rule.pattern);
        case 'link':
          return moderationContainsLink(sanitizedMessage);
        case 'spam':
        case 'flooding':
          return Array.isArray(timeline) && messageMatchesRateRule(timeline, sanitizedMessage, rule);
        default:
          return false;
      }
    })();

    if (!matched) {
      continue;
    }

    const reason = moderationReasonFromRule(rule);
    if (rule.action === 'review') {
      try {
        await persistModerationAction('review', {
          room_id: roomId,
          room_type: roomType,
          target_user_id: senderId,
          host_user_id: hostUserId,
          reason,
          message: sanitizedMessage,
          rule_key: rule.rule_key,
        });
      } catch (e) {
        console.error('[moderation][ERR]', nowISO(), `review persist failed: ${e.message}`);
      }
      return {
        allow: false,
        action: 'review',
        message: 'Message sent to moderation review.',
      };
    }

    if (rule.action === 'kick') {
      try {
        await persistModerationAction('kick', {
          room_id: roomId,
          room_type: roomType,
          target_user_id: senderId,
          host_user_id: hostUserId,
          reason,
          rule_key: rule.rule_key,
        });
      } catch (e) {
        console.error('[moderation][ERR]', nowISO(), `kick persist failed: ${e.message}`);
        return {
          allow: false,
          action: 'kick',
          message: 'You were removed from this room.',
          fallback_local: true,
        };
      }
      return {
        allow: false,
        action: 'kick',
        message: 'You were removed from this room.',
      };
    }

    if (rule.action === 'block') {
      try {
        await persistModerationAction('block', {
          room_id: roomId,
          room_type: roomType,
          target_user_id: senderId,
          host_user_id: hostUserId,
          reason,
          rule_key: rule.rule_key,
        });
      } catch (e) {
        console.error('[moderation][ERR]', nowISO(), `block persist failed: ${e.message}`);
        return {
          allow: false,
          action: 'warn',
          message: 'Message blocked by moderation rules.',
        };
      }
      let blockedUsers = moderationCache.hostBlocks.get(hostUserId);
      if (!blockedUsers) {
        blockedUsers = new Set();
        moderationCache.hostBlocks.set(hostUserId, blockedUsers);
      }
      blockedUsers.add(senderId);
      return {
        allow: false,
        action: 'block',
        message: 'You were blocked by this host.',
      };
    }

    return {
      allow: false,
      action: 'warn',
      message: 'Your message violates room moderation rules.',
    };
  }

  return {
    allow: true,
    message: sanitizedMessage,
  };
}

function audioAliasForRoomEvent(type) {
  if (type === 'live' || type === 'created') return 'audio_room:created';
  if (type === 'updated') return 'audio_room:updated';
  if (type === 'ended') return 'audio_room:ended';
  return null;
}

function audioAliasForSeatEvent(eventName) {
  const mapping = {
    'seat:request_created': 'audio_room:mic_request_created',
    'seat:request_accepted': 'audio_room:mic_request_accepted',
    'seat:request_rejected': 'audio_room:mic_request_rejected',
    'seat:request_cancelled': 'audio_room:mic_request_cancelled',
    'speaker:added': 'audio_room:speaker_added',
    'speaker:removed': 'audio_room:speaker_removed',
    'speaker:muted': 'audio_room:speaker_muted',
    'speaker:unmuted': 'audio_room:speaker_unmuted',
    'speakers:updated': 'audio_room:listener_count_updated',
  };
  return mapping[eventName] || null;
}

async function publishRoomAudience(roomId) {
  if (!roomId) return;
  const roomName = `room:${roomId}`;
  const audience = socketsInRoom(roomName);

  roomsNs.to(roomName).emit('room:audience', {
    room_id: String(roomId),
    audience,
    at: nowISO(),
  });

  try {
    const key = roomKey(roomId);
    const raw = await redis.get(key);
    const doc = raw ? JSON.parse(raw) : { id: String(roomId), status: 'live' };
    doc.audience_count = audience;
    if (isAudioRoom(doc)) {
      doc.listener_count = audienceCountForRoom(doc);
    } else {
      doc.viewer_count = audienceCountForRoom(doc);
    }
    await redis.set(key, JSON.stringify(doc));
    console.log('[rooms][AUD]', nowISO(), JSON.stringify({
      room_id: String(roomId),
      audience_count: audience,
      participants_db: Number(doc.participant_count || 0),
      viewers_db: Number(doc.viewer_count || 0),
    }));
  } catch (e) {
    console.error('[rooms][ERR]', nowISO(), `audience update failed room=${roomId}`, e.message);
  }
}

// Upsert a room doc into Redis (and update live set)
async function upsertRoomFromEvent(room, type) {
  if (!room || !room.id) return null;
  const id = String(room.id);
  const key = roomKey(id);

  let prev = {};
  try {
    const rawPrev = await redis.get(key);
    prev = rawPrev ? JSON.parse(rawPrev) : {};
  } catch {
    prev = {};
  }

  const doc = {
    id,
    room_id: id,
    title: room.title ?? prev.title ?? '',
    room_type: room.room_type ?? prev.room_type ?? 'video',
    host_id: room.host_id ?? prev.host_id ?? null,
    host_name: room.host_name ?? prev.host_name ?? null,
    host_profile_frame: room.host_profile_frame ?? prev.host_profile_frame ?? null,
    status: room.status ?? prev.status ?? 'scheduled',
    capacity: Number(room.capacity ?? prev.capacity ?? 0),
    max_speakers: Number(room.max_speakers ?? prev.max_speakers ?? 4),
    thumbnail: room.thumbnail ?? prev.thumbnail ?? null,
    started_at: room.started_at ?? prev.started_at ?? null,
    participant_count: Number(
      room.participant_count ??
      room.audience_count ??
      prev.participant_count ??
      prev.audience_count ??
      0
    ),
    viewer_count: Number(
      room.viewer_count ??
      room.audience_count ??
      room.participant_count ??
      prev.viewer_count ??
      prev.audience_count ??
      prev.participant_count ??
      0
    ),
    peak_viewers: Number(
      room.peak_viewers ??
      room.viewer_count ??
      room.audience_count ??
      room.participant_count ??
      prev.peak_viewers ??
      prev.viewer_count ??
      prev.audience_count ??
      prev.participant_count ??
      0
    ),
    audience_count: Number(
      room.audience_count ??
      room.participant_count ??
      room.viewer_count ??
      prev.audience_count ??
      prev.participant_count ??
      prev.viewer_count ??
      0
    ),
    speaker_count: Number(
      room.speaker_count ??
      prev.speaker_count ??
      0
    ),
    listener_count: Number(
      room.listener_count ??
      prev.listener_count ??
      0
    ),
    max_participants: Number(
      room.max_participants ??
      prev.max_participants ??
      50
    ),
    is_locked: Boolean(
      room.is_locked ??
      prev.is_locked ??
      false
    ),
    topic: room.topic ?? prev.topic ?? null,
    language: room.language ?? prev.language ?? null,
    end_reason: room.end_reason ?? prev.end_reason ?? null,
    pending_seat_request_count: Number(
      room.pending_seat_request_count ??
      prev.pending_seat_request_count ??
      0
    ),
  };

  await redis.set(key, JSON.stringify(doc));

  if (type === 'ended' || doc.status === 'ended') {
    await redis.srem(roomsLiveSet, doc.id);
  } else if (doc.status === 'live') {
    await redis.sadd(roomsLiveSet, doc.id);
  } else {
    await redis.srem(roomsLiveSet, doc.id);
  }
  return doc;
}
function makeLivekitToken({ room, identity, name = 'SanityUser', ttlSec = 3600, canPublish = true, canSubscribe = true }) {
  const now = Math.floor(Date.now() / 1000);
  const grants = { video: { roomJoin: true, room, canPublish, canSubscribe } };

  return jwt.sign(
    {
      jti: randomUUID(),
      iss: LK_API_KEY,
      sub: identity,
      name,
      nbf: now - 10,
      iat: now,
      exp: now + ttlSec,
      grants,
    },
    LK_API_SECRET,
    { algorithm: 'HS256' },
  );
}

// Snapshot helper with DEBUG logs
async function roomsSnapshot(limit = ROOMS_SNAPSHOT_LIMIT) {
  const ids = await redis.smembers(roomsLiveSet);
  console.log('[rooms][DBG]', nowISO(), `snapshot: liveSet=${ids.length}`);
  if (!ids.length) return [];
  const some = ids.slice(0, limit);
  const keys = some.map((id) => roomKey(id));
  const docs = await redis.mget(keys);
  const parsed = (docs || []).map((j, idx) => {
    try {
      const doc = j ? JSON.parse(j) : null;
      return { id: some[idx], doc };
    } catch {
      return { id: some[idx], doc: null };
    }
  });

  const staleIds = parsed
    .filter(({ doc }) => !doc || doc.status !== 'live' || doc.ended_at)
    .map(({ id }) => id)
    .filter(Boolean);

  if (staleIds.length) {
    await redis.srem(roomsLiveSet, ...staleIds);
  }

  const list = parsed
    .map(({ doc }) => doc)
    .filter(Boolean)
    .filter((doc) => doc.status === 'live' && !doc.ended_at)
    .filter((doc) => roomTypeFeatureEnabled(doc.room_type));
  console.log('[rooms][DBG]', nowISO(), `snapshot: returning ${list.length} docs`);
  return list;
}

// Fanout helper with DEBUG logs
function fanoutRoomEvent(payload) {
  const type = payload?.type;
  const rid  = payload?.room?.id;
  if (!roomTypeFeatureEnabled(payload?.room?.room_type)) {
    return;
  }
  const totalSockets = roomsNs.sockets.size;
  console.log('[rooms][EVT]', nowISO(), `fanout type=${type} room=${rid} sockets=${totalSockets}`);
  roomsNs.emit('rooms:event', payload);
  if (rid) {
    const room = `room:${rid}`;
    console.log('[rooms][EVT]', nowISO(), `fanout -> ${room} listeners=${socketsInRoom(room)}`);
    roomsNs.to(room).emit('room:event', payload);
    if (isAudioRoom(payload?.room)) {
      const alias = audioAliasForRoomEvent(type);
      if (alias) {
        roomsNs.to(room).emit(alias, payload);
      }
    }
  }
}

// Subscriptions
sub.subscribe('rooms:events', (err) => {
  if (err) console.error('[rooms][ERR]', nowISO(), 'subscribe rooms:events', err.message);
  else console.log('[rooms][SUB]', nowISO(), 'subscribed channel rooms:events');
});

sub.subscribe('rooms:seat-events', (err) => {
  if (err) console.error('[rooms][ERR]', nowISO(), 'subscribe rooms:seat-events', err.message);
  else console.log('[rooms][SUB]', nowISO(), 'subscribed channel rooms:seat-events');
});

sub.subscribe('rooms:gift-events', (err) => {
  if (err) console.error('[rooms][ERR]', nowISO(), 'subscribe rooms:gift-events', err.message);
  else console.log('[rooms][SUB]', nowISO(), 'subscribed channel rooms:gift-events');
});

sub.subscribe('rooms:moderation-events', (err) => {
  if (err) console.error('[rooms][ERR]', nowISO(), 'subscribe rooms:moderation-events', err.message);
  else console.log('[rooms][SUB]', nowISO(), 'subscribed channel rooms:moderation-events');
});

sub.subscribe('rooms:entry-effects', (err) => {
  if (err) console.error('[rooms][ERR]', nowISO(), 'subscribe rooms:entry-effects', err.message);
  else console.log('[rooms][SUB]', nowISO(), 'subscribed channel rooms:entry-effects');
});

sub.subscribe('rooms:pk-events', (err) => {
  if (err) console.error('[rooms][ERR]', nowISO(), 'subscribe rooms:pk-events', err.message);
  else console.log('[rooms][SUB]', nowISO(), 'subscribed channel rooms:pk-events');
});

sub.subscribe('users:block', (err) => {
  if (err) console.error('[presence][ERR]', nowISO(), 'subscribe users:block', err.message);
  else console.log('[presence]', nowISO(), 'subscribed channel users:block');
});

// live user notifications (admin approvals, etc.)
sub.subscribe('users:notify', (err) => {
  if (err) console.error('[notify][ERR]', nowISO(), 'subscribe users:notify', err.message);
  else console.log('[notify][SUB]', nowISO(), 'subscribed channel users:notify');
});

sub.subscribe('users:availability', (err) => {
  if (err) console.error('[calls][ERR]', nowISO(), 'subscribe users:availability', err.message);
  else console.log('[calls][SUB]', nowISO(), 'subscribed channel users:availability');
});

sub.subscribe('calls:events', (err) => {
  if (err) console.error('[calls][ERR]', nowISO(), 'subscribe calls:events', err.message);
  else console.log('[calls][SUB]', nowISO(), 'subscribed channel calls:events');
});

sub.subscribe('games:teen_patti:events', (err) => {
  if (err) console.error('[games][ERR]', nowISO(), 'subscribe games:teen_patti:events', err.message);
  else console.log('[games][SUB]', nowISO(), 'subscribed channel games:teen_patti:events');
});

sub.subscribe('games:greedy:events', (err) => {
  if (err) console.error('[games][ERR]', nowISO(), 'subscribe games:greedy:events', err.message);
  else console.log('[games][SUB]', nowISO(), 'subscribed channel games:greedy:events');
});

sub.on('message', async (channel, message) => {
  await getAppConfig();
  if (channel === 'rooms:events') {
    console.log('[rooms][SUB]', nowISO(), 'recv rooms:events raw.len=', (message || '').length);
    try {
      const payload = JSON.parse(message || '{}'); // {type, room, at}
      console.log('[rooms][SUB]', nowISO(), 'parsed payload:', {
        type: payload.type, id: payload?.room?.id, status: payload?.room?.status
      });

      const type = payload.type;
      const room = payload.room;
      if (!type || !room) {
        console.log('[rooms][SUB]', nowISO(), 'skip: missing type/room');
        return;
      }
      if (!roomTypeFeatureEnabled(room.room_type)) {
        return;
      }

      if (type === 'ended') {
        await upsertRoomFromEvent(room, type);
      } else if (type === 'live' || type === 'created' || type === 'updated') {
        await upsertRoomFromEvent(room, type);
      }
      fanoutRoomEvent(payload);
    } catch (e) {
      console.error('[rooms][ERR]', nowISO(), 'rooms:events parse', e.message, message);
    }
  } else if (channel === 'users:block') {
    try {
      const payload = JSON.parse(message || '{}');
      if (payload.user_id) await forceLogout(payload.user_id, 'blocked');
    } catch (e) {
      console.error('[presence][ERR]', nowISO(), 'users:block parse', e.message, message);
    }
  } else if (channel === 'users:notify') {
    try {
      const payload = JSON.parse(message || '{}'); // {user_id, type, title, body, meta, at}
      const uid = Number(payload.user_id);
      if (!uid) return;
      const n = emitToUser(uid, 'notify', payload);
      console.log('[notify][EVT]', nowISO(), `notify -> user=${uid} sockets=${n} type=${payload.type}`);
    } catch (e) {
      console.error('[notify][ERR]', nowISO(), 'users:notify parse', e.message, message);
    }
  } else if (channel === 'users:availability') {
    try {
      if (!featureEnabled('host_calling_enabled')) {
        return;
      }
      const payload = JSON.parse(message || '{}');
      console.log('[calls][EMIT]', nowISO(), JSON.stringify({
        event: 'user_availability_updated',
        user_id: Number(payload.user_id || 0),
      }));
      callsNs.emit('user_availability_updated', payload);
      presenceNs.emit('user_availability_updated', payload);
    } catch (e) {
      console.error('[calls][ERR]', nowISO(), 'users:availability parse', e.message, message);
    }
  } else if (channel === 'calls:events') {
    try {
      if (!featureEnabled('host_calling_enabled')) {
        return;
      }
      const payload = JSON.parse(message || '{}');
      const callerId = Number(payload.caller_id || 0);
      const receiverId = Number(payload.receiver_id || 0);
      const eventName = payload.event;
      if (!eventName) return;

      if (eventName === 'incoming_call' && receiverId) {
        console.log('[calls][EMIT]', nowISO(), JSON.stringify({
          event: eventName,
          call_id: payload.call_id,
          receiver_id: receiverId,
        }));
        callsNs.to(`user:${receiverId}`).emit('incoming_call', payload);
        return;
      }

      if (callerId) {
        console.log('[calls][EMIT]', nowISO(), JSON.stringify({
          event: eventName,
          call_id: payload.call_id,
          user_id: callerId,
          target: 'caller',
        }));
        callsNs.to(`user:${callerId}`).emit(eventName, payload);
      }
      if (receiverId) {
        console.log('[calls][EMIT]', nowISO(), JSON.stringify({
          event: eventName,
          call_id: payload.call_id,
          user_id: receiverId,
          target: 'receiver',
        }));
        callsNs.to(`user:${receiverId}`).emit(eventName, payload);
      }
    } catch (e) {
      console.error('[calls][ERR]', nowISO(), 'calls:events parse', e.message, message);
    }
  } else if (channel === 'rooms:seat-events') {
    try {
      const payload = JSON.parse(message || '{}');
      if (!roomTypeFeatureEnabled(payload.room_type)) {
        return;
      }
      const roomId = String(payload.room_id || '');
      const eventName = String(payload.event || '');
      if (!roomId || !eventName) return;

      const roomName = `room:${roomId}`;
      console.log('[rooms][SEAT]', nowISO(), JSON.stringify({
        event: eventName,
        room_id: roomId,
        user_id: Number(payload.user_id || 0),
        room_listeners: socketsInRoom(roomName),
      }));

      roomsNs.to(roomName).emit(eventName, payload);
      if (String(payload.room_type || 'video') === 'audio') {
        const alias = audioAliasForSeatEvent(eventName);
        if (alias) {
          roomsNs.to(roomName).emit(alias, payload);
        }
        if (eventName === 'speaker:added' || eventName === 'speaker:removed' || eventName === 'speakers:updated') {
          roomsNs.to(roomName).emit('audio_room:participant_updated', payload);
        }
      }
    } catch (e) {
      console.error('[rooms][ERR]', nowISO(), 'rooms:seat-events parse', e.message, message);
    }
  } else if (channel === 'rooms:gift-events') {
    try {
      if (!featureEnabled('gifts_enabled')) {
        return;
      }
      const payload = JSON.parse(message || '{}');
      if (!roomTypeFeatureEnabled(payload.room_type)) {
        return;
      }
      const roomId = String(payload.room_id || '');
      const opponentRoomId = String(payload.opponent_room_id || '');
      const eventName = String(payload.event || 'room:gift');
      if (!roomId) return;

      const targetRoomIds = Array.from(new Set([roomId, opponentRoomId].filter(Boolean)));

      for (const targetRoomId of targetRoomIds) {
        const roomName = `room:${targetRoomId}`;
        console.log('[rooms][GIFT]', nowISO(), JSON.stringify({
          event: eventName,
          room_id: roomId,
          target_room_id: targetRoomId,
          sender_user_id: Number(payload.sender_user_id || 0),
          room_listeners: socketsInRoom(roomName),
        }));

        roomsNs.to(roomName).emit(eventName, payload);
        if (String(payload.room_type || 'video') === 'audio') {
          roomsNs.to(roomName).emit('audio_room:gift_sent', payload);
        }
      }
    } catch (e) {
      console.error('[rooms][ERR]', nowISO(), 'rooms:gift-events parse', e.message, message);
    }
  } else if (channel === 'rooms:moderation-events') {
    try {
      const payload = JSON.parse(message || '{}');
      const roomId = String(payload.room_id || '').trim();
      const eventName = String(payload.event || 'room:moderation:system_message');
      if (eventName === 'moderation:cache:invalidate') {
        invalidateModerationCache();
        void getModerationSnapshot(true);
        return;
      }
      if (roomId) {
        const roomName = `room:${roomId}`;
        roomsNs.to(roomName).emit(eventName, payload);
        if (payload.message) {
          roomsNs.to(roomName).emit('room:moderation:system_message', payload);
        }
      }

      const targetUserId = Number(payload.target_user_id || 0);
      if (targetUserId > 0) {
        emitToUser(targetUserId, eventName, payload);
        if (eventName === 'room:user:blocked' || eventName === 'room:user:kicked') {
          emitToUser(targetUserId, 'room:user:moderation_target', payload);
        }
      }
    } catch (e) {
      console.error('[rooms][ERR]', nowISO(), 'rooms:moderation-events parse', e.message, message);
    }
  } else if (channel === 'rooms:entry-effects') {
    try {
      if (!featureEnabled('entry_effects_enabled')) {
        return;
      }
      const payload = JSON.parse(message || '{}');
      const roomId = String(payload.room_id || '');
      if (!roomId) return;

      const roomName = `room:${roomId}`;
      console.log('[rooms][ENTRY]', nowISO(), JSON.stringify({
        event: 'room:entry_effect',
        room_id: roomId,
        user_id: Number(payload.user_id || 0),
        entry_pack_id: Number(payload.entry_pack_id || 0),
        room_listeners: socketsInRoom(roomName),
      }));

      roomsNs.to(roomName).emit('room:entry_effect', payload);
    } catch (e) {
      console.error('[rooms][ERR]', nowISO(), 'rooms:entry-effects parse', e.message, message);
    }
  } else if (channel === 'rooms:pk-events') {
    try {
      if (!featureEnabled('pk_battles_enabled')) {
        return;
      }
      const payload = JSON.parse(message || '{}');
      const eventName = String(payload.event || '');
      const roomAId = String(payload.room_a?.id || payload.room_a_id || '');
      const roomBId = String(payload.room_b?.id || payload.room_b_id || '');
      if (!eventName || (!roomAId && !roomBId)) return;

      let targetRoomIds = [roomAId, roomBId];
      if (eventName === 'pk:invite_sent') {
        targetRoomIds = [roomAId];
      } else if (eventName === 'pk:invite_received') {
        targetRoomIds = [roomBId];
      }

      for (const roomId of targetRoomIds) {
        if (!roomId) continue;
        const roomName = `room:${roomId}`;
        console.log('[rooms][PK]', nowISO(), JSON.stringify({
          event: eventName,
          battle_id: payload.battle_id,
          room_id: roomId,
          room_listeners: socketsInRoom(roomName),
        }));
        roomsNs.to(roomName).emit(eventName, payload);
      }
    } catch (e) {
      console.error('[rooms][ERR]', nowISO(), 'rooms:pk-events parse', e.message, message);
    }
  } else if (channel === 'games:teen_patti:events') {
    try {
      const payload = JSON.parse(message || '{}');
      console.log('[games][EVT]', nowISO(), JSON.stringify({
        event: payload.event,
        round_key: payload.round_key || payload.snapshot?.round?.round_key || null,
        sockets: gamesNs.sockets.size,
      }));
      gamesNs.emit('games:event', payload);
      if (payload.event) {
        gamesNs.emit(payload.event, payload);
      }
      await fetchTeenPattiSnapshotInternal(true);
    } catch (e) {
      console.error('[games][ERR]', nowISO(), 'games:teen_patti:events parse', e.message, message);
    }
  } else if (channel === 'games:greedy:events') {
    try {
      const payload = JSON.parse(message || '{}');
      console.log('[games][EVT]', nowISO(), JSON.stringify({
        event: payload.event,
        round_key: payload.round_key || payload.snapshot?.round?.round_key || null,
        sockets: gamesNs.sockets.size,
      }));
      gamesNs.emit('games:event', payload);
      if (payload.event) {
        gamesNs.emit(payload.event, payload);
      }
      await fetchGreedySnapshotInternal(true);
    } catch (e) {
      console.error('[games][ERR]', nowISO(), 'games:greedy:events parse', e.message, message);
    }
  }
});

roomsNs.on('connection', (socket) => {
  addSocketMap(socket);
  const uid = socket.user?.id;
  const joinedRoomIds = new Set();
  console.log('[rooms][CONN]', nowISO(), `client connected sid=${socket.id} user=${uid} total=${roomsNs.sockets.size}`);

  // ✅ Enforce single active device per NAMESPACE: only kicks other /rooms sockets
  kickOtherSocketsInNs(uid, '/rooms', socket.id, 'new_login');

  socket.on('rooms:subscribe', async () => {
    await getAppConfig();
    if (!featureEnabled('audio_rooms_enabled') && !featureEnabled('video_rooms_enabled')) {
      socket.emit('feature:error', featureErrorPayload(
        'LIVE_ROOMS_DISABLED',
        'Live rooms are currently unavailable.',
      ));
      return;
    }
    console.log('[rooms][API ]', nowISO(), `rooms:subscribe by user=${uid}`);
    const list = await roomsSnapshot();
    console.log('[rooms][API ]', nowISO(), `rooms:subscribe -> send ${list.length} rooms`);
    socket.emit('rooms:snapshot', { rooms: list });
  });

  socket.on('rooms:join', async ({ room_id }) => {
    await getAppConfig();
    if (!room_id) {
      console.error('[rooms][ERR]', nowISO(), `rooms:join missing room_id user=${uid} sid=${socket.id}`);
      return;
    }
    let roomDoc = null;
    try {
      const raw = await redis.get(roomKey(room_id));
      roomDoc = raw ? JSON.parse(raw) : null;
    } catch {}
    if (roomDoc && !roomTypeFeatureEnabled(roomDoc.room_type)) {
      socket.emit('feature:error', featureErrorPayload(
        String(roomDoc.room_type || 'video') === 'audio' ? 'AUDIO_ROOMS_DISABLED' : 'VIDEO_ROOMS_DISABLED',
        String(roomDoc.room_type || 'video') === 'audio'
          ? 'Audio rooms are currently unavailable.'
          : 'Video rooms are currently unavailable.',
        { room_id: String(room_id) },
      ));
      return;
    }
    const moderationSnapshot = await getModerationSnapshot();
    const hostUserId = Number(roomDoc?.host_id || 0);
    const joinBlocked = moderationSnapshot.available
      ? isUserBlockedByHost(hostUserId, socket.user?.id)
      : ((await moderationJoinCheck(socket.authToken, room_id))?.allow === false);
    if (joinBlocked) {
      socket.emit('room:moderation:error', {
        room_id: String(room_id),
        code: 'HOST_BLOCKED',
        message: 'You were blocked by this host.',
        at: nowISO(),
      });
      return;
    }
    const room = `room:${room_id}`;
    await clearModerationTimeline(room_id, socket.user?.id);
    socket.join(room);
    joinedRoomIds.add(String(room_id));
    cancelPendingRoomLeave(uid, room_id);
    console.log('[rooms][DBG]', nowISO(), `participant joined room=${room_id} user=${uid} sid=${socket.id} audience=${socketsInRoom(room)}`);
    socket.to(room).emit('room:user_joined', {
      room_id: String(room_id),
      user_id: Number(socket.user?.id || 0),
      name: String(socket.user?.name || 'User'),
      avatar_url: socket.user?.avatar_url || null,
      profile_frame: socket.user?.profile_frame || null,
      active_theme_key: String(socket.user?.active_theme_key || 'midnight'),
      is_vip: !!socket.user?.is_vip,
      is_host: Array.isArray(socket.user?.roles)
        ? socket.user.roles.map((role) => String(role).toLowerCase()).includes('host')
        : false,
      level: Number.isFinite(Number(socket.user?.level))
        ? Number(socket.user.level)
        : null,
    });
    const doc = roomDoc ? JSON.stringify(roomDoc) : await redis.get(roomKey(room_id));
    console.log('[rooms][API ]', nowISO(), `rooms:join user=${uid} -> ${room} audience=${socketsInRoom(room)}`);
    if (doc) {
      try {
        socket.emit('room:snapshot', JSON.parse(doc));
      } catch (e) {
        console.error('[rooms][ERR]', nowISO(), `invalid room doc for room_id=${room_id}`, e.message);
      }
    }
    await publishRoomAudience(room_id);
  });

  socket.on('rooms:leave', async ({ room_id }) => {
    if (!room_id) return;
    const room = `room:${room_id}`;
    socket.leave(room);
    joinedRoomIds.delete(String(room_id));
    cancelPendingRoomLeave(uid, room_id);
    console.log('[rooms][API ]', nowISO(), `rooms:leave user=${uid} <- ${room} audience=${socketsInRoom(room)}`);
    await publishRoomAudience(room_id);
  });

  socket.on('user:profile:refresh', async () => {
    const beforeThemeKey = socket.user?.active_theme_key || null;
    const refreshedUser = await refreshSocketUserFromLaravel(socket);
    console.log('[rooms][AUTH]', nowISO(), JSON.stringify({
      user_id: socket.user?.id || null,
      event: 'user:profile:refresh',
      success: !!refreshedUser,
      active_theme_key_before: beforeThemeKey,
      active_theme_key_after: socket.user?.active_theme_key || null,
    }));
    if (!refreshedUser) {
      return;
    }
    const profilePayload = {
      user_id: Number(socket.user?.id || 0),
      active_theme_key: socket.user?.active_theme_key || 'midnight',
      is_vip: !!socket.user?.is_vip,
      level: Number.isFinite(Number(socket.user?.level))
        ? Number(socket.user.level)
        : null,
      avatar_url: socket.user?.avatar_url || null,
      roles: Array.isArray(socket.user?.roles) ? socket.user.roles : [],
    };
    for (const roomId of joinedRoomIds) {
      roomsNs.to(`room:${roomId}`).emit('room:user:profile_updated', {
        room_id: roomId,
        ...profilePayload,
      });
    }
  });

  socket.on('room:message:send', async (payload = {}) => {
    await getAppConfig();
    if (!featureEnabled('audio_rooms_enabled') && !featureEnabled('video_rooms_enabled')) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload(
          'LIVE_ROOMS_DISABLED',
          'Live rooms are currently unavailable.',
          payload.room_id,
        ),
      );
      return;
    }

    const roomId = String(payload.room_id || '').trim();
    const roomName = `room:${roomId}`;
    let roomDoc = null;
    try {
      const raw = await redis.get(roomKey(roomId));
      roomDoc = raw ? JSON.parse(raw) : null;
    } catch {}
    const roomType = String(roomDoc?.room_type || payload.room_type || 'video')
      .trim()
      .toLowerCase();
    if (!roomId || !joinedRoomIds.has(roomId)) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload(
          'ROOM_NOT_JOINED',
          'Join the room before sending messages.',
          roomId,
        ),
      );
      return;
    }
    if (!roomTypeFeatureEnabled(roomType)) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload(
          roomType === 'audio' ? 'AUDIO_ROOMS_DISABLED' : 'VIDEO_ROOMS_DISABLED',
          roomType === 'audio'
            ? 'Audio rooms are currently unavailable.'
            : 'Video rooms are currently unavailable.',
          roomId,
        ),
      );
      return;
    }
    if (socket.user?.blocked) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload('USER_BLOCKED', 'You cannot send messages.', roomId),
      );
      return;
    }

    const message = sanitizeRoomMessage(payload.message);
    if (!message) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload('MESSAGE_EMPTY', 'Message cannot be empty.', roomId),
      );
      return;
    }
    if (message.length > ROOM_CHAT_MAX_LENGTH) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload(
          'MESSAGE_TOO_LONG',
          `Message must be ${ROOM_CHAT_MAX_LENGTH} characters or less.`,
          roomId,
        ),
      );
      return;
    }
    if (!withinRoomChatRateLimit(socket.user?.id)) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload(
          'RATE_LIMITED',
          'You are sending messages too fast. Please slow down.',
          roomId,
        ),
      );
      return;
    }
    const moderation = await evaluateRoomModeration(roomDoc || {
      room_id: roomId,
      room_type: roomType,
    }, socket, message);
    if (moderation && moderation.allow === false) {
      socket.emit(
        'room:message:error',
        roomMessageErrorPayload(
          String(moderation.action || 'MODERATION_BLOCKED').toUpperCase(),
          String(moderation.message || 'Message blocked by moderation rules.'),
          roomId,
        ),
      );
      if (moderation.action === 'kick' || moderation.action === 'block') {
        if (moderation.action === 'kick' && moderation.fallback_local === true) {
          const kickedPayload = {
            event: 'room:user:kicked',
            room_id: roomId,
            room_type: roomType,
            host_user_id: Number(roomDoc?.host_id || 0),
            target_user_id: Number(socket.user?.id || 0),
            reason: 'Auto moderation fallback',
            message: `${String(socket.user?.name || 'User')} was removed by system`,
            at: nowISO(),
          };
          socket.emit('room:user:kicked', kickedPayload);
          socket.to(roomName).emit('room:moderation:system_message', kickedPayload);
        }
        await clearModerationTimeline(roomId, socket.user?.id);
        socket.leave(roomName);
        joinedRoomIds.delete(String(roomId));
        await publishRoomAudience(roomId);
      } else {
        await clearModerationTimeline(roomId, socket.user?.id);
      }
      return;
    }

    const senderName = String(socket.user?.name || `User#${socket.user?.id || ''}`).trim();
    const eventPayload = {
      room_id: roomId,
      room_type: roomType,
      sender_id: Number(socket.user?.id || 0),
      sender_name: senderName || 'User',
      sender_avatar: socket.user?.avatar_url || null,
      sender_profile_frame: socket.user?.profile_frame || null,
      sender_level: Number.isFinite(Number(socket.user?.level))
        ? Number(socket.user.level)
        : null,
      sender_is_vip: !!socket.user?.is_vip,
      sender_active_theme_key: socket.user?.active_theme_key || 'midnight',
      message: String(moderation?.message || message),
      message_type: 'text',
      created_at: nowISO(),
      sender_is_host: socketHasRole(socket, 'host'),
    };

    console.log('[rooms][CHAT]', nowISO(), JSON.stringify({
      room_id: roomId,
      sender_id: eventPayload.sender_id,
      room_listeners: socketsInRoom(roomName),
      len: message.length,
    }));
    roomsNs.to(roomName).emit('room:message:new', eventPayload);
  });

  socket.on('disconnect', async (reason) => {
    for (const roomId of joinedRoomIds) {
      schedulePendingRoomLeave({
        userId: uid,
        roomId,
        token: socket.authToken,
        reason,
      });
      await publishRoomAudience(roomId);
    }
    removeSocketMap(socket);
    console.log('[rooms][CONN]', nowISO(), `disconnect sid=${socket.id} user=${uid} reason=${reason} total=${roomsNs.sockets.size}`);
  });
});

gamesNs.on('connection', (socket) => {
  addSocketMap(socket);
  const uid = socket.user?.id;
  console.log('[games][CONN]', nowISO(), `client connected sid=${socket.id} user=${uid} total=${gamesNs.sockets.size}`);

  kickOtherSocketsInNs(uid, '/games', socket.id, 'new_login');

  socket.on('games:teen_patti:subscribe', async () => {
    await getAppConfig();
    if (!featureEnabled('teen_patti_enabled')) {
      socket.emit('feature:error', featureErrorPayload(
        'TEEN_PATTI_DISABLED',
        'Teen Patti is currently unavailable.',
      ));
      return;
    }

    socket.join('game:teen_patti');
    const snapshot = await fetchTeenPattiSnapshotInternal(true);
    if (snapshot) {
      socket.emit('teen_patti:snapshot', snapshot);
    }
  });

  socket.on('games:teen_patti:unsubscribe', () => {
    socket.leave('game:teen_patti');
  });

  socket.on('games:greedy:subscribe', async () => {
    await getAppConfig();
    if (!featureEnabled('greedy_enabled')) {
      socket.emit('feature:error', featureErrorPayload(
        'GREEDY_DISABLED',
        'Greedy is currently unavailable.',
      ));
      return;
    }

    socket.join('game:greedy');
    const snapshot = await fetchGreedySnapshotInternal(true);
    if (snapshot) {
      socket.emit('greedy:snapshot', snapshot);
    }
  });

  socket.on('games:greedy:unsubscribe', () => {
    socket.leave('game:greedy');
  });

  socket.on('disconnect', (reason) => {
    removeSocketMap(socket);
    console.log('[games][CONN]', nowISO(), `disconnect sid=${socket.id} user=${uid} reason=${reason} total=${gamesNs.sockets.size}`);
  });
});

callsNs.on('connection', (socket) => {
  addSocketMap(socket);
  const uid = Number(socket.user.id);

  kickOtherSocketsInNs(uid, '/calls', socket.id, 'new_login');
  socket.join(`user:${uid}`);
  syncSocketPresenceWithLaravel(socket.authToken, 'online');
  console.log('[calls][CONN]', nowISO(), JSON.stringify({
    action: 'connected',
    socket_id: socket.id,
    user_id: uid,
    total: callsNs.sockets.size,
  }));

  socket.on('disconnect', (reason) => {
    removeSocketMap(socket);
    console.log('[calls][CONN]', nowISO(), JSON.stringify({
      action: 'disconnected',
      socket_id: socket.id,
      user_id: uid,
      reason,
    }));
    if (!hasPresenceCarrier(uid)) {
      syncSocketPresenceWithLaravel(socket.authToken, 'offline');
    }
  });
});

setInterval(async () => {
  if (!hasAnyActiveSockets()) {
    return;
  }

  const previous = appConfigCache.data;
  const latest = await getAppConfig(true);

  if (!previous.maintenance_mode_enabled && latest.maintenance_mode_enabled) {
    const payload = featureErrorPayload(
      'MAINTENANCE_MODE',
      'The platform is temporarily unavailable for maintenance.',
    );
    disconnectNamespace(presenceNs, 'maintenance_mode', payload);
    disconnectNamespace(roomsNs, 'maintenance_mode', payload);
    disconnectNamespace(callsNs, 'maintenance_mode', payload);
    disconnectNamespace(gamesNs, 'maintenance_mode', payload);
    return;
  }

  if (latest.force_app_upgrade_enabled) {
    disconnectUnsupportedSockets(
      'force_upgrade',
      (info) => info.platform === 'android'
        && Number(info.appVersionCode || 0) < Number(latest.android_min_version_code || 1),
      () => featureErrorPayload(
        'APP_UPGRADE_REQUIRED',
        latest.android_update_message,
        { minimum_android_version_code: Number(latest.android_min_version_code || 1) },
      ),
    );
  }

  if (!latest.features.host_calling_enabled) {
    disconnectNamespace(
      callsNs,
      'host_calling_disabled',
      featureErrorPayload('HOST_CALLING_DISABLED', 'Host calling is currently unavailable.'),
    );
  }

  if (!latest.features.teen_patti_enabled && !latest.features.greedy_enabled) {
    disconnectNamespace(
      gamesNs,
      'games_disabled',
      featureErrorPayload('GAMES_DISABLED', 'Room games are currently unavailable.'),
    );
  }
}, APP_CONFIG_POLL_MS);

setInterval(async () => {
  if (!hasAnyActiveSockets()) {
    return;
  }
  await getModerationSnapshot(true);
}, MODERATION_CACHE_POLL_MS);

setInterval(async () => {
  if (gamesNs.sockets.size <= 0) {
    return;
  }
  await fetchTeenPattiSnapshotInternal(false);
  await fetchGreedySnapshotInternal(false);
}, 1000);

// ===================================================================
//                              Health & Debug
// ===================================================================
app.get('/health', async (_req, res) => {
  try {
    const online = await redis.scard(presenceSet);
    const liveRoomsCount = await redis.scard(roomsLiveSet);
    res.json({
      ok: true,
      ts: nowISO(),
      presence_online: online,
      rooms_live: liveRoomsCount,
    });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// 🔎 DEBUG: list live rooms + docs
app.get('/debug/rooms', async (_req, res) => {
  try {
    const ids = await redis.smembers(roomsLiveSet);
    const docs = await roomsSnapshot(200);
    res.json({ ok: true, ts: nowISO(), live_ids: ids, rooms: docs });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});
// Prefilled sanity route: returns a token you can use immediately
app.get('/debug/livekit/token', (req, res) => {
  if (!LK_API_KEY || !LK_API_SECRET) {
    return res.status(500).json({ ok: false, error: 'Missing LK_API_KEY / LK_API_SECRET in env' });
  }
  // Allow quick overrides via query, but default to known-good values
  const room = String(req.query.room || 'testroom');
  const identity = String(req.query.identity || 'user1');

  try {
    const token = makeLivekitToken({ room, identity });
    return res.json({
      ok: true,
      room,
      identity,
      ws_url: `ws://${req.hostname || 'YOUR_VPS_IP'}:7880`,
      token,
      hint: 'Use ws://<YOUR_VPS_IP>:7880 with this token',
    });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});
// 🔎 DEBUG: fetch single room doc
app.get('/debug/rooms/:id', async (req, res) => {
  try {
    const id = String(req.params.id);
    const json = await redis.get(roomKey(id));
    res.json({ ok: true, ts: nowISO(), room: json ? JSON.parse(json) : null });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

server.on('error', (err) => {
  console.error('[common][ERR]', nowISO(), 'server.listen failed:', err.message);
  process.exitCode = 1;
});

server.listen(PORT, () => {
  console.log('[common]', nowISO(), `Listening on :${PORT}`);
  void getAppConfig(true);
  void getModerationSnapshot(true);
});
