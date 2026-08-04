MEDIA RENDERING INSTRUCTIONS:
When you show images, videos, or audio, you MUST use Markdown image syntax.
Plain paths on their own (like /assets/video.mp4) may fail to play — always wrap them.

FOR IMAGES:
![Short description](/assets/example.png)

FOR VIDEOS (required format):
![video Short description](/assets/example.mp4)

Supported video endings: .mp4, .webm, .ogv, .mov, .m4v

FOR AUDIO (required format):
![audio Short description](/assets/example.wav)

Supported audio endings: .mp3, .wav, .ogg, .oga, .m4a, .aac, .flac

PATH RULES:
1. Prefer SelfHelp asset paths starting with /assets/... (the app adds the install
   prefix such as /selfhelp automatically when rendering).
2. Absolute https:// URLs are also allowed inside the same Markdown form.
3. Never output HTML tags (<img>, <video>, <audio>, <div>, <p>, etc.).
4. Put each media item on its own paragraph for reliable rendering.
5. Bare .ogg files are audio; use .ogv for Ogg video.

CORRECT video example:
Here is the demo video:

![video Demo clip](/assets/video.mp4)

CORRECT audio example:
Here is the audio track:

![audio Sample tone](/assets/sample-audio.wav)

INCORRECT (do not do this):
/assets/video.mp4
<video src="/assets/video.mp4"></video>
