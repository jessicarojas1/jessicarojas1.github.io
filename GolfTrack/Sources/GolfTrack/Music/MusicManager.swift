import Foundation
import MediaPlayer
#if canImport(MusicKit)
import MusicKit
#endif

enum MusicApp: String, CaseIterable, Identifiable {
    case apple    = "Apple Music"
    case spotify  = "Spotify"
    case youtube  = "YouTube Music"
    case amazon   = "Amazon Music"
    case tidal    = "Tidal"

    var id: String { rawValue }

    var systemImage: String {
        switch self {
        case .apple:   return "music.note"
        case .spotify: return "music.note.list"
        case .youtube: return "play.rectangle.fill"
        case .amazon:  return "music.quarternote.3"
        case .tidal:   return "waveform"
        }
    }

    /// URL scheme to open the app (iOS only)
    var openURL: URL? {
        switch self {
        case .apple:   return URL(string: "music://")
        case .spotify: return URL(string: "spotify://")
        case .youtube: return URL(string: "youtubemusic://")
        case .amazon:  return URL(string: "amznmusic://")
        case .tidal:   return URL(string: "tidal://")
        }
    }
}

@Observable
final class MusicManager {
    var nowPlayingTitle  = ""
    var nowPlayingArtist = ""
    var isPlaying        = false
    var artworkImage: Any? = nil   // UIImage on iOS

    private let player = MPMusicPlayerController.systemMusicPlayer
    private var timer: Timer?

    init() {
        startPolling()
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(nowPlayingDidChange),
            name: .MPMusicPlayerControllerNowPlayingItemDidChange,
            object: player
        )
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(playbackStateDidChange),
            name: .MPMusicPlayerControllerPlaybackStateDidChange,
            object: player
        )
        player.beginGeneratingPlaybackNotifications()
    }

    deinit {
        player.endGeneratingPlaybackNotifications()
        timer?.invalidate()
    }

    // MARK: - Controls

    func togglePlayPause() {
        if player.playbackState == .playing { player.pause() } else { player.play() }
        refreshNowPlaying()
    }

    func skipNext()     { player.skipToNextItem();     refreshNowPlaying() }
    func skipPrevious() { player.skipToPreviousItem(); refreshNowPlaying() }

    // MARK: - Launch music apps

    func open(_ app: MusicApp) {
#if os(iOS)
        guard let url = app.openURL else { return }
        if UIApplication.shared.canOpenURL(url) {
            UIApplication.shared.open(url)
        } else {
            // Fall back to App Store search
            let search = "https://apps.apple.com/search?term=\(app.rawValue.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "")"
            if let storeURL = URL(string: search) {
                UIApplication.shared.open(storeURL)
            }
        }
#endif
    }

    // MARK: - Private

    private func startPolling() {
        timer = Timer.scheduledTimer(withTimeInterval: 2, repeats: true) { [weak self] _ in
            self?.refreshNowPlaying()
        }
        refreshNowPlaying()
    }

    @objc private func nowPlayingDidChange()   { refreshNowPlaying() }
    @objc private func playbackStateDidChange() { refreshNowPlaying() }

    private func refreshNowPlaying() {
        let item = player.nowPlayingItem
        nowPlayingTitle  = item?.title  ?? ""
        nowPlayingArtist = item?.artist ?? ""
        isPlaying = player.playbackState == .playing

#if os(iOS)
        if let artwork = item?.artwork {
            artworkImage = artwork.image(at: CGSize(width: 40, height: 40))
        } else {
            artworkImage = nil
        }
#endif
    }
}
