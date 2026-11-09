# Using OpenVisit

For providers and front desk staff. Your administrator sets OpenVisit up first; see
[install.md](install.md).

## Before the visit

Book the appointment in the calendar with the **Telehealth Visit** category. Telehealth
appointments carry a camera icon. Patients with a portal account see a **Join video visit**
button on the appointment; everyone else gets a link from you. Once you end the visit, the
portal shows that it has ended instead of the button.

A patient who has no appointment can still be seen: open **Telehealth** in the main menu, search
the patient under *Start a visit without an appointment* and click **Start video visit**. OpenVisit
books the appointment for you.

## Running the visit

1. Start it from the appointment (open it in the calendar, **Start video visit**) or from the
   **Telehealth** page, which lists today's video visits.
2. The visit opens in a window over OpenEMR. Check your camera and microphone, then click
   **Join meeting**. The encounter is opened for you.
3. Click **Invite**, then **New patient link**, and send the link to the patient by any channel:
   text, email, chat. With email invitations on, **Email patient** sends it from OpenEMR.
   For an interpreter or a family member, add a guest link with their name.
4. The patient opens the link on a phone or a computer, with no app and no account, and waits
   in the lobby. You see who is waiting; click **Admit**.
5. Click **End visit** when you are done. The call closes for everyone and the encounter gets the
   real start and end time.

Links work only around the appointment time and can be revoked from the Invite panel. A link to
a visit that has ended tells the patient so.

## Working in OpenEMR during the call

The call keeps running while you use the rest of OpenEMR. Open the chart, write the note, look
up results: as soon as you move to another menu or tab, the call shrinks into the bottom-right
corner. The small window keeps what you need:

| Control | What it does |
|---|---|
| Microphone, camera | Mute and unmute; red means off |
| Expand | Back to the large window |
| Leave | Leave the call; the visit stays open and you can rejoin from Telehealth |
| Admit | Appears when someone waits in the lobby |

Drag either window by its top bar; the small one settles into the nearest corner. Double-click
the bar to switch sizes. The rim shows the state at a glance: green in the call, amber when
someone is waiting, red while recording. **Open in a browser tab** moves the call to its own tab,
for example to put it on a second screen.

Logging out or reloading OpenEMR ends your side of the call. Log in again and click **Rejoin**
on the Telehealth page; you skip the lobby and are back with the patient.

## Recording

When your administrator has turned recording on, a **Record** button sits in the call window.

- It works once at least two people are in the call: you and the patient or a guest.
- Ask the patient first. Clicking Record asks you to confirm that the patient agreed.
- Everyone sees that the visit is recorded. The patient's page shows a banner.
- It stops when you click **Stop recording**, when fewer than two people are left, or when you
  end the visit.

A minute or two later the recording appears on the encounter page, under **Video visit**, and in
**Visit history** on the Telehealth page. Click a preview to play it in a player that stays on
top while you keep working. Only the provider of the visit and administrators can watch it, and
every viewing is logged.

## What the patient sees

The link opens a page with their first name, the appointment time and a camera check. After
**Join meeting** they wait until you admit them. If you record, a banner says so. When you end
the visit, their page says the visit has ended. They need a current browser on a phone, tablet
or computer, and permission for the camera and microphone.

## When something goes wrong

| What happens | What to do |
|---|---|
| The Start button is missing | The appointment needs the Telehealth Visit category and a patient. |
| The setup check shows a red line | Each line says what to fix; most are settings in Globals > OpenVisit. |
| The patient's link says it expired or is too early | Links open shortly before the appointment; create a new link from Invite. |
| The patient cannot hear or see you | Ask them to allow camera and microphone in the browser, then reload the page. |
| You land in the lobby of your own visit | The Jitsi server lacks the OpenVisit prosody plugin; see install.md, Jitsi server. |
| Record stays off | It needs two people in the call. If it still fails, the recorder (Jibri) is not running; tell your administrator. |
| A recording shows Saving for a long time | Jibri is still writing the file; it appears once done, or shows Not saved after 12 hours. |
