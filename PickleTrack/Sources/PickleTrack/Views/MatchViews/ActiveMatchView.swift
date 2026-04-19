import SwiftUI

struct ActiveMatchView: View {
    @Environment(ActiveMatchManager.self) private var mgr
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    @State private var showDiscard = false

    var body: some View {
        NavigationStack {
            Group {
                if let match = mgr.match, let game = mgr.currentGame {
                    VStack(spacing: 0) {
                        // Games won bar
                        GamesWonBar(match: match)
                            .padding(.horizontal).padding(.vertical, 8)
                            .background(.regularMaterial)

                        Divider()

                        Spacer()

                        // Scoreboard
                        ScoreboardView(match: match, game: game, mgr: mgr)
                            .padding(.horizontal, 24)

                        Spacer()

                        // Score announcement
                        VStack(spacing: 4) {
                            Text("Score Call")
                                .font(.caption2.uppercaseSmallCaps())
                                .foregroundStyle(.secondary)
                            Text(mgr.announcement)
                                .font(.system(size: 22, weight: .bold, design: .rounded))
                                .foregroundStyle(.green)
                        }
                        .padding(.vertical, 12)

                        Divider()

                        // Point buttons
                        HStack(spacing: 0) {
                            PointButton(label: match.team1DisplayName, color: .blue) {
                                mgr.scorePoint(team: 1, context: context)
                            }
                            Divider()
                            PointButton(label: match.team2DisplayName, color: .orange) {
                                mgr.scorePoint(team: 2, context: context)
                            }
                        }
                        .frame(height: 100)
                        .background(.regularMaterial)
                    }
                } else {
                    ContentUnavailableView("No Active Match", systemImage: "sportscourt")
                }
            }
            .navigationTitle(mgr.match?.displayName ?? "Active Match")
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Discard") { showDiscard = true }.foregroundStyle(.red)
                }
                ToolbarItem(placement: .topBarLeading) {
                    Button {
                        mgr.undoLastPoint(context: context)
                    } label: {
                        Image(systemName: "arrow.uturn.backward")
                    }
                    .disabled(mgr.currentGame?.points.isEmpty ?? true)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Finish") {
                        mgr.finishEarly(context: context)
                        dismiss()
                    }
                    .fontWeight(.bold)
                    .foregroundStyle(.green)
                }
            }
        }
        .alert("Discard Match?", isPresented: $showDiscard) {
            Button("Discard", role: .destructive) {
                mgr.discardMatch(context: context)
                dismiss()
            }
            Button("Cancel", role: .cancel) {}
        } message: { Text("This match will not be saved.") }
    }
}

// MARK: - Games Won Bar

private struct GamesWonBar: View {
    let match: PBMatch
    var body: some View {
        HStack {
            Text(match.team1DisplayName)
                .font(.caption.bold())
                .lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .leading)
            HStack(spacing: 6) {
                ForEach(0..<((match.bestOf / 2) + 1), id: \.self) { i in
                    Circle()
                        .fill(i < match.team1GamesWon ? Color.blue : Color.secondary.opacity(0.2))
                        .frame(width: 10, height: 10)
                }
                Text("vs")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .padding(.horizontal, 4)
                ForEach(0..<((match.bestOf / 2) + 1), id: \.self) { i in
                    Circle()
                        .fill(i < match.team2GamesWon ? Color.orange : Color.secondary.opacity(0.2))
                        .frame(width: 10, height: 10)
                }
            }
            Text(match.team2DisplayName)
                .font(.caption.bold())
                .lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .trailing)
        }
    }
}

// MARK: - Scoreboard

private struct ScoreboardView: View {
    let match: PBMatch
    let game:  PBGame
    let mgr:   ActiveMatchManager

    var body: some View {
        HStack(alignment: .center, spacing: 0) {
            // Team 1
            TeamScoreColumn(
                name:        match.team1DisplayName,
                score:       game.team1Score,
                isServing:   mgr.servingTeam == 1,
                serverNum:   mgr.serverNumber,
                showServerNum: match.format == .doubles,
                color:       .blue
            )

            // Divider
            Text(":")
                .font(.system(size: 60, weight: .thin))
                .foregroundStyle(.secondary)
                .padding(.horizontal, 8)

            // Team 2
            TeamScoreColumn(
                name:        match.team2DisplayName,
                score:       game.team2Score,
                isServing:   mgr.servingTeam == 2,
                serverNum:   mgr.serverNumber,
                showServerNum: match.format == .doubles,
                color:       .orange
            )
        }
        .frame(maxWidth: .infinity)
    }
}

private struct TeamScoreColumn: View {
    let name:          String
    let score:         Int
    let isServing:     Bool
    let serverNum:     Int
    let showServerNum: Bool
    let color:         Color

    var body: some View {
        VStack(spacing: 8) {
            // Serve indicator
            HStack(spacing: 4) {
                if isServing {
                    Image(systemName: "circle.fill")
                        .font(.system(size: 8))
                        .foregroundStyle(color)
                    if showServerNum {
                        Text("Server \(serverNum)")
                            .font(.caption2)
                            .foregroundStyle(color)
                    } else {
                        Text("Serving")
                            .font(.caption2)
                            .foregroundStyle(color)
                    }
                } else {
                    Image(systemName: "circle")
                        .font(.system(size: 8))
                        .foregroundStyle(.secondary.opacity(0.4))
                    Text("Receiving")
                        .font(.caption2)
                        .foregroundStyle(.clear)
                }
            }
            .frame(height: 16)

            // Score
            Text("\(score)")
                .font(.system(size: 80, weight: .black, design: .rounded))
                .foregroundStyle(isServing ? color : .primary)
                .contentTransition(.numericText())
                .animation(.spring(duration: 0.3), value: score)

            // Name
            Text(name)
                .font(.callout.bold())
                .multilineTextAlignment(.center)
                .lineLimit(2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - Point Button

private struct PointButton: View {
    let label:  String
    let color:  Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: "plus.circle.fill")
                    .font(.title)
                    .foregroundStyle(color)
                Text(label)
                    .font(.caption.bold())
                    .lineLimit(1)
                    .foregroundStyle(.primary)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
