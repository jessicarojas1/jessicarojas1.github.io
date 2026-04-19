import SwiftUI

struct MusicPlayerBar: View {
    @Environment(MusicManager.self) private var music
    @State private var showAppPicker = false

    var body: some View {
        VStack(spacing: 0) {
            Divider()
            HStack(spacing: 12) {
                // Artwork / placeholder
                ZStack {
                    RoundedRectangle(cornerRadius: 6)
                        .fill(Color.secondary.opacity(0.2))
                        .frame(width: 36, height: 36)
#if os(iOS)
                    if let img = music.artworkImage as? UIImage {
                        Image(uiImage: img)
                            .resizable()
                            .scaledToFill()
                            .frame(width: 36, height: 36)
                            .clipShape(RoundedRectangle(cornerRadius: 6))
                    } else {
                        Image(systemName: "music.note")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
#else
                    Image(systemName: "music.note")
                        .font(.caption)
                        .foregroundStyle(.secondary)
#endif
                }

                // Track info
                VStack(alignment: .leading, spacing: 1) {
                    Text(music.nowPlayingTitle.isEmpty ? "Not Playing" : music.nowPlayingTitle)
                        .font(.caption.weight(.semibold))
                        .lineLimit(1)
                    if !music.nowPlayingArtist.isEmpty {
                        Text(music.nowPlayingArtist)
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)

                // Controls
                HStack(spacing: 4) {
                    ControlButton(systemName: "backward.fill") { music.skipPrevious() }
                    ControlButton(systemName: music.isPlaying ? "pause.fill" : "play.fill") { music.togglePlayPause() }
                    ControlButton(systemName: "forward.fill") { music.skipNext() }
                }

                // App picker
                Button {
                    showAppPicker = true
                } label: {
                    Image(systemName: "music.note.list")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .frame(width: 28, height: 28)
                        .background(Color.secondary.opacity(0.15), in: Circle())
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 8)
            .background(.regularMaterial)
        }
        .sheet(isPresented: $showAppPicker) {
            MusicAppPickerSheet()
        }
    }
}

private struct ControlButton: View {
    let systemName: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: systemName)
                .font(.caption.weight(.semibold))
                .foregroundStyle(.primary)
                .frame(width: 30, height: 30)
        }
        .buttonStyle(.plain)
    }
}

struct MusicAppPickerSheet: View {
    @Environment(MusicManager.self) private var music
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List(MusicApp.allCases) { app in
                Button {
                    music.open(app)
                    dismiss()
                } label: {
                    Label(app.rawValue, systemImage: app.systemImage)
                }
            }
            .navigationTitle("Open Music App")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
        .presentationDetents([.medium])
    }
}
