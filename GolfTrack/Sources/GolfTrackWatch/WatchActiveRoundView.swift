import SwiftUI

struct WatchActiveRoundView: View {
    @Environment(WatchSessionManager.self) private var session
    @State private var showPutts = false

    var body: some View {
        ScrollView {
            VStack(spacing: 6) {
                // Course + hole header
                Text(session.courseName.isEmpty ? "Hole \(session.holeNumber)" : session.courseName)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)

                HStack {
                    VStack(spacing: 2) {
                        Text("HOLE")
                            .font(.system(size: 9))
                            .foregroundStyle(.secondary)
                        Text("\(session.holeNumber)")
                            .font(.system(size: 28, weight: .bold))
                    }
                    Spacer()
                    VStack(spacing: 2) {
                        Text("PAR")
                            .font(.system(size: 9))
                            .foregroundStyle(.secondary)
                        Text("\(session.par)")
                            .font(.system(size: 28, weight: .bold))
                    }
                    Spacer()
                    VStack(spacing: 2) {
                        Text("YDS")
                            .font(.system(size: 9))
                            .foregroundStyle(.secondary)
                        Text(session.yardage > 0 ? "\(session.yardage)" : "—")
                            .font(.system(size: 28, weight: .bold))
                    }
                }
                .padding(.horizontal, 4)

                Divider()

                // Strokes stepper
                VStack(spacing: 4) {
                    Text("STROKES")
                        .font(.system(size: 9, weight: .semibold))
                        .foregroundStyle(.secondary)
                    HStack(spacing: 16) {
                        Button {
                            let newVal = max(1, session.strokes - 1)
                            session.strokes = newVal
                            session.sendStrokeUpdate(strokes: newVal)
                        } label: {
                            Image(systemName: "minus.circle.fill")
                                .font(.title3)
                                .foregroundStyle(.red)
                        }
                        .buttonStyle(.plain)

                        Text("\(session.strokes)")
                            .font(.system(size: 36, weight: .bold, design: .rounded))
                            .frame(minWidth: 44)

                        Button {
                            let newVal = session.strokes + 1
                            session.strokes = newVal
                            session.sendStrokeUpdate(strokes: newVal)
                        } label: {
                            Image(systemName: "plus.circle.fill")
                                .font(.title3)
                                .foregroundStyle(.green)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.vertical, 4)

                // Putts stepper
                VStack(spacing: 4) {
                    Text("PUTTS")
                        .font(.system(size: 9, weight: .semibold))
                        .foregroundStyle(.secondary)
                    HStack(spacing: 16) {
                        Button {
                            let newVal = max(0, session.putts - 1)
                            session.putts = newVal
                            session.sendPuttUpdate(putts: newVal)
                        } label: {
                            Image(systemName: "minus.circle.fill")
                                .font(.title3)
                                .foregroundStyle(.red)
                        }
                        .buttonStyle(.plain)

                        Text("\(session.putts)")
                            .font(.system(size: 36, weight: .bold, design: .rounded))
                            .frame(minWidth: 44)

                        Button {
                            let newVal = session.putts + 1
                            session.putts = newVal
                            session.sendPuttUpdate(putts: newVal)
                        } label: {
                            Image(systemName: "plus.circle.fill")
                                .font(.title3)
                                .foregroundStyle(.green)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.vertical, 4)

                Divider()

                // Prev / Next hole navigation
                HStack(spacing: 8) {
                    Button {
                        session.sendPrevHole()
                    } label: {
                        Image(systemName: "chevron.left.circle.fill")
                            .font(.title3)
                            .foregroundStyle(.blue)
                    }
                    .buttonStyle(.plain)

                    Spacer()

                    // Score vs par pill
                    ScoreVsParBadge(scoreVsPar: session.scoreVsPar)

                    Spacer()

                    Button {
                        session.sendNextHole()
                    } label: {
                        Image(systemName: "chevron.right.circle.fill")
                            .font(.title3)
                            .foregroundStyle(.blue)
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 4)

                // Total
                HStack {
                    Text("Total:")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                    Text("\(session.totalStrokes)")
                        .font(.caption2.bold())
                    Text("(\(session.holesPlayed)/\(session.holesTotal))")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
                .padding(.top, 2)
            }
            .padding(.horizontal, 4)
            .padding(.vertical, 8)
        }
        .navigationTitle("")
    }
}

private struct ScoreVsParBadge: View {
    let scoreVsPar: Int

    var color: Color {
        switch scoreVsPar {
        case ..<(-1): return .yellow
        case -1:      return .yellow
        case 0:       return .green
        case 1:       return .blue
        default:      return .red
        }
    }

    var label: String {
        switch scoreVsPar {
        case 0:  return "E"
        default: return scoreVsPar > 0 ? "+\(scoreVsPar)" : "\(scoreVsPar)"
        }
    }

    var body: some View {
        Text(label)
            .font(.system(size: 13, weight: .bold))
            .foregroundStyle(.black)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(color, in: Capsule())
    }
}
