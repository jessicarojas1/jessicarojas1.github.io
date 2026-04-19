import SwiftUI

struct ActiveRoundView: View {
    @Environment(ActiveRoundManager.self) private var roundManager
    @Environment(WatchConnectivityManager.self) private var watchManager
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    @State private var selectedHole = 0
    @State private var showDiscard = false

    var round: Round? { roundManager.round }

    var body: some View {
        NavigationStack {
            Group {
                if let round {
                    VStack(spacing: 0) {
                        // Score summary bar
                        ScoreSummaryBar(round: round)
                            .padding(.horizontal).padding(.vertical, 10)
                            .background(.regularMaterial)

                        Divider()

                        // Hole selector
                        ScrollViewReader { proxy in
                            ScrollView(.horizontal, showsIndicators: false) {
                                HStack(spacing: 6) {
                                    ForEach(round.sortedScores) { hole in
                                        HoleTabButton(
                                            hole: hole,
                                            isSelected: selectedHole == hole.holeNumber - 1
                                        ) {
                                            selectedHole = hole.holeNumber - 1
                                        }
                                        .id(hole.holeNumber)
                                    }
                                }
                                .padding(.horizontal).padding(.vertical, 8)
                            }
                            .onChange(of: selectedHole) { _, val in
                                withAnimation { proxy.scrollTo(val + 1, anchor: .center) }
                            }
                        }

                        Divider()

                        // Hole scoring card
                        let scores = round.sortedScores
                        if selectedHole < scores.count {
                            HoleScoringView(
                                hole: scores[selectedHole],
                                onNext: {
                                    if selectedHole < scores.count - 1 { selectedHole += 1 }
                                },
                                onPrev: {
                                    if selectedHole > 0 { selectedHole -= 1 }
                                }
                            )
                            .padding()
                            .onChange(of: scores[selectedHole].strokes) { _, _ in
                                watchManager.sendHoleUpdate(hole: scores[selectedHole], round: round)
                            }
                            .onChange(of: scores[selectedHole].putts) { _, _ in
                                watchManager.sendHoleUpdate(hole: scores[selectedHole], round: round)
                            }
                        }

                        Spacer()

                        // Music mini-player
                        MusicPlayerBar()
                    }
                } else {
                    ContentUnavailableView("No Active Round", systemImage: "flag")
                }
            }
            .navigationTitle(round?.courseName ?? "Active Round")
            .onReceive(NotificationCenter.default.publisher(for: .watchNextHole)) { _ in
                if let scores = round?.sortedScores, selectedHole < scores.count - 1 {
                    selectedHole += 1
                }
            }
            .onReceive(NotificationCenter.default.publisher(for: .watchPrevHole)) { _ in
                if selectedHole > 0 { selectedHole -= 1 }
            }
            .onReceive(NotificationCenter.default.publisher(for: .watchScoreUpdate)) { note in
                guard let info = note.userInfo,
                      let holeNum = info["holeNumber"] as? Int,
                      let strokes = info["strokes"] as? Int,
                      let hole = round?.sortedScores.first(where: { $0.holeNumber == holeNum })
                else { return }
                roundManager.setStrokes(strokes, hole: hole)
            }
            .onReceive(NotificationCenter.default.publisher(for: .watchPuttUpdate)) { note in
                guard let info = note.userInfo,
                      let holeNum = info["holeNumber"] as? Int,
                      let putts = info["putts"] as? Int,
                      let hole = round?.sortedScores.first(where: { $0.holeNumber == holeNum })
                else { return }
                roundManager.setPutts(putts, hole: hole)
            }
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Discard") { showDiscard = true }.foregroundStyle(.red)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Finish Round") {
                        roundManager.finishRound()
                        dismiss()
                    }
                    .fontWeight(.bold)
                    .foregroundStyle(.green)
                }
            }
        }
        .alert("Discard Round?", isPresented: $showDiscard) {
            Button("Discard", role: .destructive) {
                roundManager.discardRound(context: context)
                dismiss()
            }
            Button("Cancel", role: .cancel) {}
        } message: { Text("This round will not be saved.") }
    }
}

// MARK: - Score Summary Bar

struct ScoreSummaryBar: View {
    let round: Round
    var body: some View {
        HStack(spacing: 20) {
            VStack(spacing: 2) {
                Text("\(round.totalStrokes)").font(.title2.bold())
                Text("Strokes").font(.caption2).foregroundStyle(.secondary)
            }
            Divider().frame(height: 28)
            VStack(spacing: 2) {
                Text(round.scoreLabel)
                    .font(.title2.bold())
                    .foregroundStyle(round.scoreVsPar < 0 ? .green : round.scoreVsPar == 0 ? .primary : .red)
                Text("vs Par").font(.caption2).foregroundStyle(.secondary)
            }
            Divider().frame(height: 28)
            VStack(spacing: 2) {
                Text("\(round.totalPutts)").font(.title2.bold())
                Text("Putts").font(.caption2).foregroundStyle(.secondary)
            }
            Divider().frame(height: 28)
            VStack(spacing: 2) {
                Text("\(round.holesCompleted)/\(round.totalHoles)").font(.title2.bold())
                Text("Holes").font(.caption2).foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - Hole Tab Button

struct HoleTabButton: View {
    let hole: HoleScore
    let isSelected: Bool
    let action: () -> Void

    private var scoreColor: Color {
        if hole.strokes == 0 { return .secondary }
        switch hole.scoreVsPar {
        case ..<0:  return .yellow
        case 0:     return .green
        case 1:     return Color.blue
        default:    return .red
        }
    }

    var body: some View {
        Button(action: action) {
            VStack(spacing: 2) {
                Text("\(hole.holeNumber)").font(.caption.bold())
                Text(hole.strokes > 0 ? "\(hole.strokes)" : "-")
                    .font(.callout.bold())
                    .foregroundStyle(scoreColor)
            }
            .frame(width: 38, height: 44)
            .background(isSelected ? Color.green.opacity(0.2) : Color.secondary.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .overlay(isSelected ? RoundedRectangle(cornerRadius: 8).stroke(Color.green, lineWidth: 1.5) : nil)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Hole Scoring View

struct HoleScoringView: View {
    @Environment(ActiveRoundManager.self) private var mgr
    @Environment(ClubProfile.self) private var clubProfile
    @Bindable var hole: HoleScore
    let onNext: () -> Void
    let onPrev: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            // Hole info header
            HStack {
                Button(action: onPrev) { Image(systemName: "chevron.left").font(.title3) }
                    .buttonStyle(.plain).foregroundStyle(.secondary)
                Spacer()
                VStack(spacing: 2) {
                    Text("Hole \(hole.holeNumber)").font(.title2.bold())
                    HStack(spacing: 12) {
                        Text("Par \(hole.par)").font(.callout).foregroundStyle(.secondary)
                        Text("\(hole.yardage) yds").font(.callout).foregroundStyle(.secondary)
                    }
                }
                Spacer()
                Button(action: onNext) { Image(systemName: "chevron.right").font(.title3) }
                    .buttonStyle(.plain).foregroundStyle(.secondary)
            }

            // Club recommendation
            if hole.yardage > 0 {
                ClubRecommendationCard(yardage: hole.yardage)
            }

            // Score name banner
            if hole.strokes > 0 {
                Text(hole.scoreName)
                    .font(.headline)
                    .padding(.horizontal, 16).padding(.vertical, 6)
                    .background(scoreNameColor.opacity(0.15))
                    .foregroundStyle(scoreNameColor)
                    .clipShape(Capsule())
            }

            // Stroke counter
            VStack(spacing: 6) {
                Text("Strokes").font(.caption).foregroundStyle(.secondary)
                HStack(spacing: 24) {
                    StepperButton(icon: "minus", color: .red)   { mgr.setStrokes(hole.strokes - 1, hole: hole) }
                    Text("\(hole.strokes)").font(.system(size: 52, weight: .bold, design: .rounded))
                    StepperButton(icon: "plus",  color: .green) { mgr.setStrokes(hole.strokes + 1, hole: hole) }
                }
            }
            .padding()
            .background(.regularMaterial)
            .clipShape(RoundedRectangle(cornerRadius: 14))

            // Putts counter
            VStack(spacing: 6) {
                Text("Putts").font(.caption).foregroundStyle(.secondary)
                HStack(spacing: 24) {
                    StepperButton(icon: "minus", color: .red)   { mgr.setPutts(hole.putts - 1, hole: hole) }
                    Text("\(hole.putts)").font(.system(size: 38, weight: .bold, design: .rounded))
                    StepperButton(icon: "plus",  color: .green) { mgr.setPutts(hole.putts + 1, hole: hole) }
                }
            }

            // Fairway / GIR toggles
            HStack(spacing: 12) {
                if hole.fairwayHit != nil {
                    ToggleChip(label: "Fairway", icon: "arrow.up",
                               isOn: hole.fairwayHit ?? false, color: .blue) {
                        mgr.toggleFairway(hole)
                    }
                }
                ToggleChip(label: "GIR", icon: "circle.fill",
                           isOn: hole.girHit, color: .green) {
                    mgr.toggleGIR(hole)
                }
            }

            // Penalties
            HStack {
                Text("Penalty Strokes").font(.callout)
                Spacer()
                HStack(spacing: 16) {
                    Button { hole.penaltyStrokes = max(0, hole.penaltyStrokes - 1) } label: {
                        Image(systemName: "minus.circle").font(.title3)
                    }
                    .buttonStyle(.plain)
                    Text("\(hole.penaltyStrokes)").font(.callout.bold()).frame(width: 20)
                    Button { hole.penaltyStrokes += 1 } label: {
                        Image(systemName: "plus.circle").font(.title3)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding()
            .background(.regularMaterial)
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    private var scoreNameColor: Color {
        switch hole.scoreVsPar {
        case ..<0:  return .yellow
        case 0:     return .green
        case 1:     return .blue
        default:    return .red
        }
    }
}

// MARK: - Helpers

struct StepperButton: View {
    let icon: String; let color: Color; let action: () -> Void
    var body: some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.title2.bold())
                .frame(width: 44, height: 44)
                .background(color.opacity(0.15))
                .foregroundStyle(color)
                .clipShape(Circle())
        }
        .buttonStyle(.plain)
    }
}

struct ToggleChip: View {
    let label: String; let icon: String; let isOn: Bool; let color: Color; let action: () -> Void
    var body: some View {
        Button(action: action) {
            Label(label, systemImage: isOn ? "checkmark." + icon : icon)
                .font(.callout)
                .padding(.horizontal, 14).padding(.vertical, 8)
                .background(isOn ? color.opacity(0.2) : Color.secondary.opacity(0.1))
                .foregroundStyle(isOn ? color : .secondary)
                .clipShape(Capsule())
                .overlay(isOn ? Capsule().stroke(color.opacity(0.4), lineWidth: 1) : nil)
        }
        .buttonStyle(.plain)
    }
}
