# Team presence

The Team page displays last activity rather than last sign-in. Authenticated page visits update `users.last_active_at`; login time remains stored separately. A visible signed-in page sends a CSRF-protected heartbeat every thirty seconds. Hidden tabs stop heartbeats. Members with a valid session active within ninety seconds are labelled online. The Team page refreshes these labels without a reload.

Presence is per session so closing/signing out of one device does not hide another active device. Revoked session epochs and disabled accounts are excluded immediately. Signing out deletes the current session's presence; abandonment expires from the online window. Historical activity remains available after going offline. Older accounts have no recorded activity until their next visit; old login times are not presented as visits.

Migration 013 adds the activity column and session-presence table automatically on deployment. It stores only a hash of the session ID; presence responses do not expose session identifiers. All authenticated roles may heartbeat; only admins can list member activity. Background data APIs do not count as page visits. Scheduled housekeeping removes presence rows older than fourteen days.
