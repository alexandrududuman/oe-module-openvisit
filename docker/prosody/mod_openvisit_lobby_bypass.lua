-- Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
-- Lets a participant whose JWT carries the moderator claim into a room with the lobby on, so a
-- provider who reloads or rejoins is not left waiting for someone to admit them. Runs before
-- token_affiliation (-3.5) and the lobby gate (-4); patients and guests still wait in the lobby.
local util = module:require 'util';
local is_healthcheck_room = util.is_healthcheck_room;

module:hook('muc-occupant-pre-join', function (event)
    local room, occupant, session = event.room, event.occupant, event.origin;
    if is_healthcheck_room(room.jid) or not room:get_members_only() then
        return;
    end
    local user = session and session.auth_token and session.jitsi_meet_context_user;
    if user and (user.moderator == true or user.moderator == 'true') then
        room:set_affiliation(true, occupant.bare_jid, 'owner');
    end
end, -3);
